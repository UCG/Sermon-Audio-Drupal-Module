<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldWidget;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\Element\ManagedFile;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\FileRenamePseudoExtensionRepository;
use Drupal\sermon_audio\Plugin\Field\FieldType\SermonAudioFieldItem;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ParseHelpers;
use Ranine\Helper\StringHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @todo Get rid of file link appearing after upload.
// @todo Figure out who owns the newly created entities.

/**
 * Edit widget for sermon audio fields.
 *
 * @FieldWidget(
 *   id = "sermon_unprocessed_audio",
 *   label = @Translation("Sermon Unprocessed Audio"),
 *   field_types = { "sermon_audio" },
 * )
 */
class SermonAudioWidget extends WidgetBase {

  // @todo Verify this.
  /* As I understand it, the operation of this widget in the context of the
  Form API is as follows:

  -- When loading an edit form containing the widget --
  1) The edit form is built, and formElement() is called to build this
  widget's form element.
  2) The value callback for this widget (static::getWidgetValue()) is invoked.
  3) The process callbacks are invoked, starting with the managed_file form
  element callback and following with static::handlePostProcessing().

  -- When an AJAX file upload / removal submission occurs --
  1), 2) and 3) above.
  4) $this->massageFormValues() is called to extract field value for validation.
  5) Validation occurs.
  6) Assuming validation is successful (@todo Check what happens what it fails),
  the form is rebuilt, and 1), 2) and 3) above are fired again.

  -- When the edit form is submitted --
  1), 2), 3) and 4) above.
  5) Validation and submission, if validation successful (@todo Check what
  happens when it fails).
  6) The form is rebuilt, and 1), 2), and 3) are fired.
  */

  /** Some of the code herein is adapted from
   * @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget */

   /**
    * Render array element info manager.
    */
   private ElementInfoManagerInterface $elementInfoManager;

   /**
    * Module configuration.
    */
   private ImmutableConfig $moduleConfiguration;

   /**
    * Repository of pseudo-extensions and bare names for to-be-renamed files.
    */
   private FileRenamePseudoExtensionRepository $renamePseudoExtensionRepo;

   /**
    * Storage for sermon audio entities.
    */
   private ContentEntityStorageInterface $sermonAudioStorage;

  /**
   * Creates a new sermon audio widget.
   *
   * @param string $pluginId
   *   Plugin ID.
   * @param mixed $pluginDefinition
   *   Plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   Definition of field associated w/ widget.
   * @param array $settings
   *   Widget settings.
   * @param array $thirdPartySettings
   *   Widget third party settings.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translationManager
   *   Translation manager.
   * @param \Drupal\sermon_audio\FileRenamePseudoExtensionRepository $renamePseudoExtensionRepo
   *   Repository of pseudo-extensions and bare names for to-be-renamed files.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface
   *   Render array element info manager.
   */
  protected function __construct(string $pluginId,
    $pluginDefinition,
    FieldDefinitionInterface $fieldDefinition,
    array $settings,
    array $thirdPartySettings,
    TranslationInterface $translationManager,
    ConfigFactoryInterface $configFactory,
    FileRenamePseudoExtensionRepository $renamePseudoExtensionRepo,
    EntityTypeManagerInterface $entityTypeManager,
    ElementInfoManagerInterface $elementInfoManager) {
    parent::__construct($pluginId, $pluginDefinition, $fieldDefinition, $settings, $thirdPartySettings);
    $this->elementInfoManager = $elementInfoManager;
    $this->moduleConfiguration = $configFactory->get('sermon_audio.settings');
    $this->renamePseudoExtensionRepo = $renamePseudoExtensionRepo;
    $this->sermonAudioStorage = $entityTypeManager->getStorage('sermon_audio');
    $this->stringTranslation = $translationManager;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) : array {
    $fieldItem = $items[$delta];
    if (!($fieldItem instanceof SermonAudioFieldItem)) {
      throw new InvalidOperationException('This method was called for a field with an item of an invalid type.');
    }

    // Prepare the default value of the form element. If there is already a
    // processed sermon audio entity, the default value should include its ID,
    // as well as the FID of the processed audio (if available) or unprocessed
    // audio (otherwise).
    $targetId = $fieldItem->get('target_id')->getValue();
    if ($targetId === '' || $targetId === NULL) {
      $defaultValue = NULL;
    }
    else {
      $sermonAudioId = (int) $targetId;
      $sermonAudio = $this->sermonAudioStorage->load($sermonAudioId);
      assert($sermonAudio instanceof SermonAudio);
      $hasProcessedAudio = $sermonAudio->hasProcessedAudio();
      $fid = $hasProcessedAudio ? $sermonAudio->getProcessedAudioFid() : $sermonAudio->getUnprocessedAudioId();
      $defaultValue = [
        'aid' => $sermonAudioId,
        // We use the "fids" array to be compatible with the managed_file form
        // element type.
        'fids' => [$fid],
        'processed' => $hasProcessedAudio,
      ];
    }
    $element = [
      // We use the built-in managed_file widget to handle the actual file
      // uploads/removals.
      '#type' => 'managed_file',
      '#progress_indicator' => $this->getSetting('progress_indicator'),
      '#upload_location' => $this->getUploadLocation(),
      '#upload_validators' => $fieldItem->getUploadValidators(),
      '#default_value' => $defaultValue,
      // The value callback takes the form input (which does *not* include any
      // actual newly uploaded file, though it may contain references to the IDs
      // of previously uploaded files) and converts it into a form value. The
      // form value contains any file ID of an uploaded file, as well as any
      // associated sermon audio ID. This value is *not* the final value used as
      // the value of the associated field item -- the value will be further
      // transformed by massageFieldsValues() into its final form. The value
      // callback is also responsible for creating, if necessary, any new file
      // or sermon audio entities needed.
      '#value_callback' => fn(array &$element, $input, FormStateInterface $formState)
        => static::getWidgetValue($element, $input, $formState, $this->getSetting('auto_rename')),
      // Add our own processing routine that runs after the default routine for
      // the managed_file form element. This may, we can get rid of the link (to
      // an uploaded file) that the existing processing routine adds.
      '#process' => array_merge($this->elementInfoManager->getInfo('managed_file')['#process'], [static::handlePostProcessing(...)]),
      // Ensure that we can encode the sermon audio in the form value.
      '#extended' => TRUE,
    ]
      // We use the passed-in $element as a base, overriding any contradicting
      // information.
      + $element;

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) : array {
    foreach ($values as &$value) {
      if (is_array($value) && array_key_exists('aid', $value)) {
        $sermonAudioId = $value['aid'];
        if (!is_int($sermonAudioId)) {
          throw new \RuntimeException('An invalid sermon audio ID was detected.');
        }
        $value = ['target_id' => $sermonAudioId];
      }
      // Otherwise, yield an empty array. The corresponding field item will then
      // automatically be removed.
      else $value = [];
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    return [
      'progress_indicator' => [
        '#type' => 'radios',
        '#title' => $this->t('Upload progress indicator'),
        '#options' => [
          'throbber' => $this->t('Throbber'),
          'bar' => $this->t('Progress bar'),
        ],
        '#default_value' => $this->getSetting('progress_indicator'),
        // file_progress_implementation() seems to indicate whether there is
        // functionality for measuring file upload status -- it should return
        // FALSE if no such functionality exists.
        '#access' => file_progress_implementation(),
      ],
      'auto_rename' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Automatically rename uploaded file'),
        '#default_value' => (bool) $this->getSetting('auto_rename'),
        '#description' => $this->t('To avoid naming conflicts, the unprocessed audio file can be given an automatic semi-random name upon upload.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() : array {
    $summaries = [
      $this->t('Progress indicator: @progress_indicator', ['@progress_indicator' => $this->getSetting('progress_indicator')]),
    ];
    if ($this->getSetting('auto_rename')) {
      $summaries[] = $this->t('Auto rename: yes');
    }
    else {
      $summaries[] = $this->t('Auto rename: no');
    }

    return $summaries;
  }

  /**
   * Validates and gets the unprocessed audio upload location.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the upload location (pulled from the module configuration) is
   *   empty.
   */
  private function getUploadLocation() : string {
    $uploadLocation = (string) $this->moduleConfiguration->get('unprocessed_audio_uri_prefix');
    if ($uploadLocation === '') {
      throw new ModuleConfigurationException('The unprocessed_audio_uri_prefix setting is empty.');
    }
    return $uploadLocation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : static {
    return new static($plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('string_translation'),
      $container->get('config.factory'),
      $container->get('sermon_audio.file_rename_pseudo_extension_repository'),
      $container->get('entity_type.manager'),
      $container->get('element_info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() : array {
    return [
      'progress_indicator' => 'throbber',
      'auto_rename' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * Gets the widget element #value from the user input.
   *
   * If there is a new file upload, the value is computed as follows. First, a
   * file entity for the uploaded file is created if possible, or, if an entity
   * has already been created during this request cycle, that entity is re-used.
   * A corresponding new sermon audio entity is also created and outputted,
   * unless, again, such an entity has already been created during this request
   * cycle (this can occur if getWidgetValue() has already been called during
   * this request cycle).
   *
   * If there is not a new file upload, but $input['fids'] contains an FID from
   * a previous upload, that FID is used in the output value provided it can be
   * verified that the user has permission to use that FID. It is then necessary
   * to determine the corresponding sermon audio ID. If one has already been
   * computed during this request cycle for that FID, that is used. Otherwise,
   * we look in a persistent key-value store for $input['aid-code'], which is a
   * token granting "attachment rights" for a particular audio ID for a certain
   * time period, and examine the value returned to see what audio ID we can use
   * (if any). If this is unsuccessful, a new sermon audio entity is generated
   * and outputted.
   *
   * If there is no input of any kind, and in certain error conditions, the
   * default value is outputted.
   *
   * @param array $element
   *   Form element.
   * @param mixed $input
   *   Value previously computed from user input, or FALSE to indicate that the
   *   element's default value should be returned.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   * @param bool $autoRenameUploads
   *   Whether newly uploaded files should automatically be given a random name.
   *   This can be used for S3 uploads to prevent the overwriting of existing
   *   files (which can happen automatically in some configurations).
   *
   * @return array
   *   Widget element value representing the file currently associated with the
   *   widget. This is an empty array if there is no such file. Otherwise, it is
   *   an array of the form ['fids' => [file ID], 'aid' => [associated sermon
   *   audio ID], 'processed' => [whether file represents processed audio]].
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs while saving an entity.
   * @throws \Ranine\Exception\ParseException
   *   Thrown if an error occurs when attempting to parse the input FID.
   * @throws \RuntimeException
   *   Thrown in some cases if $input is invalid (we don't throw an
   *   \InvalidArgumentException because of how this function is called...).
   */
  public static function getWidgetValue(array &$element, $input, FormStateInterface $formState, bool $autoRenameUploads) : array {
    // To start, we let the managed_file form element compute a value. This will
    // get us the correct file ID. Now, to keep things predictable and to avoid
    // messing up some stuff we do later on, strip anything extraneous (that is,
    // anything but the FID) from $input.
    if (is_array($input) && array_key_exists('fids', $input)) {
      $originalFidString = trim((string) $input['fids']);
      if ($originalFidString !== '') {
        $cleanInput = ['fids' => $originalFidString];
        // We should never have more than one FID.
        $originalFid = ParseHelpers::parseIntFromString($originalFidString);
      }
      else $cleanInput = [];
    }
    elseif ($input === FALSE) $cleanInput = FALSE;
    else $cleanInput = [];

    // If requested, automatically rename any new file upload.
    if ($autoRenameUploads) {
      // We use "psuedo-extensions" to force a rename of the file. Basically, we
      // add an extra extension to the "file_validate_extensions" validator
      // settings. This is not actually a relevant extension (in fact,
      // we should never get a file with this extension, given that it is a long
      // random hexadecimal string), but we include it to signal that we want to
      // rename the file. We associate this extension with the new bare
      // (extension-less)filename we wish to use.
      // @see \Drupal\sermon_audio\FileRenamePseudoExtensionRepository
      // If we already determined this "pseudo-extension" earlier, go ahead and
      // use it. Otherwise, calculate and cache a new pseudo-extension.
      $pseudoExtension =& static::getCacheReferenceForElement($element, 'pseudo_extension');

      if ($pseudoExtension === NULL) {
        // Use a random bare filename to ensure uniqueness.
        $bareFilename = bin2hex(random_bytes(8));
        $renamePseudoExtensionRepo = \Drupal::service('sermon_audio.file_rename_pseudo_extension_repository');
        assert($renamePseudoExtensionRepo instanceof FileRenamePseudoExtensionRepository);
        $pseudoExtension = $renamePseudoExtensionRepo->addBareFilename($bareFilename);
      }

      $extensionValidatorSettings =& $element['upload_validators']['file_validate_extensions'];
      $extensionList =& $extensionValidatorSettings[array_key_first($extensionValidatorSettings)];
      if (!is_string($extensionList) || $extensionList = '') $extensionList = $pseudoExtension;
      else $extensionList .= ' ' . $pseudoExtension;
    }

    $fileElementValue = ManagedFile::valueCallback($element, $cleanInput, $formState);

    if ($autoRenameUploads) {
      // Remove the fake extension from the validator settings. We don't want
      // this extension to be shown to the user!
      assert(isset($extensionValidatorSettings));
      assert(isset($pseudoExtension));
      assert(isset($extensionList));

      $extensionListLength = strlen($extensionList);
      $pseudoExtensionLength = strlen($pseudoExtension);
      if ($extensionListLength > $pseudoExtensionLength) {
        // The pseudo-extension should be on the end of the list of extensions.
        // If it is not, we'll have to search the extension list to remove it.
        if (str_ends_with($extensionList, ' ' . $pseudoExtension)) {
          $extensionList = substr($extensionList, 0, -($pseudoExtensionLength + 1));
        }
        else {
          $extensionList = str_replace($pseudoExtension . ' ', '', $extensionList);
        }
      }
      elseif ($extensionList === $pseudoExtension) {
        $extensionList = '';
      }
    }

    // If $fileElementValue includes a sermon audio ID, we know that it is the
    // default value. We don't want to mess with anything in that case...
    if (array_key_exists('aid', $fileElementValue)) return $fileElementValue;

    // If no FID was returned, return nothing...
    if (!isset($fileElementValue['fids']) || $fileElementValue['fids'] === []) {
      return [];
    }

    // Otherwise, extract the FID, and ensure the $value is presented in our
    // canonical fashion.
    $fid = (int) reset($fileElementValue['fids']);
    // We know we are working with an unprocessed file, as the only way a
    // processed file can be attached is if the default value is returned (as
    // above).
    $value = ['fids' => [$fid], 'processed' => FALSE];
    // Now we must determine the sermon audio ID. First, we check to see if we
    // have an appropriate cached audio ID from earlier on in this request
    // cycle. If so, we may use it.
    $aid = static::getCacheReferenceForElement($element, 'aid');
    if ($aid === NULL || static::getCacheReferenceForElement($element, 'fid') !== $fid) {
      // But if not, we have to determine whether we need to create a new sermon
      // audio entity or if we can re-use an existing one.
      // If the FID is different than that given in $input, we know that a new
      // file was uploaded. In that case, we'll create a new sermon audio
      // entity.
      if ($originalFid === $fid) {
        // ...But otherwise, we'll re-use the existing sermon audio entity,
        // provided the user has authorization to use it.
        if (!isset($input['aid_token'])) {
          throw new \RuntimeException('The audio ID token was missing from the form input.');
        }
        $aid = static::getAidFromToken((string) $input['aid_token']);
        if ($aid === NULL) {
          // It looks like we'll just have to create a new entity.
          $aid = static::createSermonAudioFromUnprocessedFid($fid);
        }
      }
      else {
        $aid = static::createSermonAudioFromUnprocessedFid($fid);
      }
    }
    assert(isset($aid));

    $value['aid'] = $aid;
    return $value;
  }

  /**
   * Creates/saves a new sermon audio entity from an unprocessed audio file ID.
   *
   * The new entity will have its unprocessed audio file field set in
   * correspondence with $unprocessedFid, and will have its other fields set to
   * default values.
   *
   * @param int $unprocessedFid
   *   Unprocessed file ID.
   *
   * @return int
   *   Entity ID of the new, saved sermon audio entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs while saving the entity.
   */
  private static function createSermonAudioFromUnprocessedFid(int $unprocessedFid) : int {
    $storage = \Drupal::entityTypeManager()->getStorage('sermon_audio');
    $entity = $storage->create([
      'unprocessed_audio' => ['target_id' => $unprocessedFid],
    ])->enforceIsNew();
    $entity->save();
    return (int) $entity->id();
  }

  /**
   * Gets sermon audio ID associated with particular sermon audio ID token.
   *
   * This function retrieves the ID stored in the persistent key-value store and
   * associated with the given token, if the ID exists in the store and has not
   * expired.
   *
   * @param string $token
   *   Token.
   *
   * @return int|null
   *   Sermon audio ID, or NULL if no ID was found in the store, or if the ID
   *   had expired.
   */
  private static function getAidFromToken(string $token) : ?int {
    $store = \Drupal::keyValueExpirable('sermon_audio.sermon_audio_ids');
    return $store->get($token);
  }

  /**
   * Gets a reference to cache object for a particular widget element and key.
   *
   * @param array $element
   *   Widget element.
   * @param string $key
   *   Key.
   *
   * @throws \RuntimeException
   *   Can be thrown if $element contains a bad #parent value.
   */
  private static function &getCacheReferenceForElement(array $element, string $key) : mixed {
    $elementKey = static::getElementKey($element);
    $store =& drupal_static('sermon_audio.widget_cache.' . $elementKey, []);
    if (!array_key_exists($key, $store)) {
      $store[$key] = NULL;
    }
    return $store[$key];
  }

  /**
   * Gets a unique key associated with a given widget element array.
   *
   * @param array $element
   *   Widget element.
   *
   * @return string
   *   Key.
   *
   * @throws \RuntimeException
   *   Can be thrown if $element contains a bad #parent value.
   */
  private static function getElementKey(array $element) : string {
    $key = '';
    if (!isset($element['#parents'])) {
      return $key;
    }
    if (!is_array($element['#parents'])) {
      throw new \RuntimeException('A widget element had invalid an invalid #parents key.');
    }
    $firstTime = FALSE;
    $escapeChars = [StringHelpers::ASCII_UNIT_SEPARATOR];
    foreach ($element['#parents'] as $parent) {
      if (!$firstTime) {
        $key .= StringHelpers::ASCII_UNIT_SEPARATOR;
      }
      $key .= StringHelpers::escape((string) $parent, $escapeChars);
    }

    return $key;
  }

}
