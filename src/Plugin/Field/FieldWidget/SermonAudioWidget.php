<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldWidget;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\Element\ManagedFile;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\FileRenamePseudoExtensionRepository;
use Drupal\sermon_audio\Plugin\Field\FieldType\SermonAudioFieldItem;
use Drupal\sermon_audio\Settings;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ParseHelpers;
use Ranine\Helper\StringHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

  /* As I understand it, the operation of this widget in the context of the
  Form API is as follows:

  -- When loading an edit form containing the widget --
  1) The edit form is built, and formElement() is called to build this
  widget's form element.
  2) The value callback for this widget (static::getWidgetValue()) is invoked.
  3) The process callbacks are invoked, starting with the managed_file form
  element callback and following with static::handlePostProcessing().

  -- When an AJAX file upload / removal submission occurs --
  1) (may not occur because of caching), 2) and 3) above.
  4) $this->massageFormValues() is called to extract field value for validation.
  5) Validation occurs.
  6) Assuming validation is successful, the form is rebuilt, and 1) (maybe not),
  2) and 3) are fired again.

  -- When the edit form is submitted --
  1) (may not occur because of caching), 2), 3) and 4) above.
  5) Validation, and then 4) again in the course of submission, if validation is
  successful.
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
    EntityTypeManagerInterface $entityTypeManager,
    ElementInfoManagerInterface $elementInfoManager) {
    parent::__construct($pluginId, $pluginDefinition, $fieldDefinition, $settings, $thirdPartySettings);
    $this->elementInfoManager = $elementInfoManager;
    $this->moduleConfiguration = $configFactory->get('sermon_audio.settings');
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

    $fieldDefinition = $items->getFieldDefinition();
    $element = [
      // getWidgetValue() uses the info below.
      '#custom' => [
        'entity_type' => $fieldDefinition->getTargetEntityTypeId(),
        'bundle' => $fieldDefinition->getTargetBundle(),
        'field_name' => $fieldDefinition->getName(),
      ],
      // We use the built-in managed_file widget to handle the actual file
      // uploads/removals.
      '#type' => 'managed_file',
      '#progress_indicator' => $this->getSetting('progress_indicator'),
      '#default_value' => $defaultValue,
      '#upload_location' => $this->getUploadLocation(),
      '#upload_validators' => $fieldItem->getUploadValidators(),
      // The value callback takes the form input (which does *not* include any
      // actual newly uploaded file, though it may contain references to the IDs
      // of previously uploaded files) and converts it into a form value. The
      // form value contains any file ID of an uploaded file, as well as any
      // associated sermon audio ID. This value is *not* the final value used as
      // the value of the associated field item -- the value will be further
      // transformed by massageFieldsValues() into its final form. The value
      // callback is also responsible for creating, as necessary, any new file
      // or sermon audio entities.
      '#value_callback' => [
        static::class,
        $this->getSetting('auto_rename') ? 'getWidgetValue' : 'getWidgetValueNoAutoRename',
      ],
      // Add our own processing routine that runs after the default routine for
      // the managed_file form element. This may, we can get rid of the link (to
      // an uploaded file) that the existing processing routine adds.
      '#process' => array_merge($this->elementInfoManager->getInfo('managed_file')['#process'], [[static::class, 'handlePostProcessing']]),
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
        '#description' => $this->t('To avoid naming conflicts, the unprocessed audio file can be given a random name upon upload.'),
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
   * we look in a persistent key-value store for $input['aid_token'], which is a
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
   *   Value from user form input, or FALSE to indicate that the element's
   *   default value should be returned.
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
   *   Thrown in some cases if $input or $element is invalid (we don't throw an
   *   \InvalidArgumentException because of how this function is typically
   *   called...).
   * @throws \RuntimeException
   *   Thrown if an error occurs when attempting to load a newly uploaded file
   *   entity.
   */
  public static function getWidgetValue(array &$element, $input, FormStateInterface $formState, bool $autoRenameUploads = TRUE) : array {
    $entityFieldManager = \Drupal::service('entity_field.manager');
    assert($entityFieldManager instanceof EntityFieldManagerInterface);

    if (!isset($element['#custom']['entity_type'])) {
      throw new \RuntimeException('Missing ["#custom"]["entity_type"] value in widget form element.');
    }
    if (!isset($element['#custom']['bundle'])) {
      throw new \RuntimeException('Missing ["#custom"]["bundle"] value in widget form element.');
    }
    $fieldSettings = $entityFieldManager
      ->getFieldDefinitions((string) $element['#custom']['entity_type'], (string) $element['#custom']['bundle'])
      [(string) $element['#custom']['field_name']]
      ->getSettings();

    // Re-compute the form element's upload location & validators. This is done
    // since the form element could have been cached, and thus formElement() not
    // fired during this request cycle. In turn, this could lead to a stale
    // upload location and validators, which seems a bit risky from a security
    // perspective.
    $element['#upload_location'] = static::getUploadLocationStatically();
    $element['#upload_validators'] = SermonAudioFieldItem::getUploadValidatorsForSettings($fieldSettings);

    // To start, we let the managed_file form element compute a value. This will
    // get us the correct file ID. Now, to keep things predictable and to avoid
    // messing up some stuff we do later on, strip anything extraneous (that is,
    // anything but the FID) from $input.
    $originalFid = NULL;
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
      // We use a "psuedo-extension" to force a rename of the file. Basically,
      // we add an extra extension to the "file_validate_extensions" validator
      // settings. This is not actually a relevant extension (in fact, we should
      // never get a file with this extension, given that it is a long random
      // hexadecimal string), but we include it to signal that we want to rename
      // the file. We associate this extension with the new bare
      // (extension-less) filename we wish to use.
      // @see \Drupal\sermon_audio\FileRenamePseudoExtensionRepository
      // If we already determined this "pseudo-extension" earlier, go ahead and
      // use it. Otherwise, calculate and cache a new pseudo-extension.
      $pseudoExtension =& static::getCacheReferenceForElement($element, 'pseudo_extension', $formState);

      if ($pseudoExtension === NULL) {
        // Use a random bare filename to ensure uniqueness.
        $bareFilename = bin2hex(random_bytes(8));
        $renamePseudoExtensionRepo = \Drupal::service('sermon_audio.file_rename_pseudo_extension_repository');
        assert($renamePseudoExtensionRepo instanceof FileRenamePseudoExtensionRepository);
        $pseudoExtension = $renamePseudoExtensionRepo->addBareFilename($bareFilename);
      }

      $extensionValidatorSettings =& $element['#upload_validators']['file_validate_extensions'];
      $extensionList =& $extensionValidatorSettings[array_key_first($extensionValidatorSettings)];
      if (!is_string($extensionList) || $extensionList === '') $extensionList = $pseudoExtension;
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
    // processed file can be attached is if the default value is returned (see
    // above).
    $value = ['fids' => [$fid], 'processed' => FALSE];
    // Now we must determine the sermon audio ID. First, we check to see if we
    // have an appropriate cached audio ID from earlier on in this request
    // cycle. If so, we may use it.
    $aid =& static::getCacheReferenceForElement($element, 'aid', $formState);
    $cachedFid =& static::getCacheReferenceForElement($element, 'fid', $formState);
    if ($aid === NULL || $cachedFid !== $fid) {
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
        // Also, assume an "mp4" extension means an audio mp4 file, not a video
        // mp4 (as Drupal seems to assume). We also fix the MIME type for "m4a",
        // which Drupal incorrectly sets to "audio/mpeg".
        $file = static::getFileStorage()->load($fid);
        if ($file === NULL) {
          throw new \RuntimeException('Entity for newly uploaded file could not be loaded.');
        }
        assert($file instanceof FileInterface);
        $filename = (string) $file->getFilename();
        if ($filename === '') $extension = pathinfo($file->getFileUri(), PATHINFO_EXTENSION);
        else $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (strcasecmp($extension, 'm4a') === 0 || strcasecmp($extension, 'mp4') === 0) {
          if ($file->getMimeType() !== 'audio/mp4') {
            $file->setMimeType('audio/mp4');
            $file->save();
          }
        }

        $aid = static::createSermonAudioFromUnprocessedFid($fid);
      }
      // Record the FID associated with this AID.
      $cachedFid = $fid;
    }

    assert(isset($aid));
    $value['aid'] = $aid;
    return $value;
  }

  /**
   * Calls static::getWidgetValue() with $autoRename = FALSE.
   */
  public static function getWidgetValueNoAutoRename(array &$element, $input, FormStateInterface $formState) : array {
    return static::getWidgetValue($element, $input, $formState, FALSE);
  }

  /**
   * Handles the post-processing callback for a given widget element.
   *
   * @param array $element
   *   Widget form element.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   *
   * @return array
   *   Widget form element.
   *
   * @throws \RuntimeException
   *   Can be thrown if something is wrong with $element. We don't throw an
   *   \InvalidArgumentException because of how this function is called.
   */
  public static function handlePostProcessing(array &$element, FormStateInterface $formState) : array {
    // If we don't have a sermon audio ID, we shouldn't have a file either, and
    // we have nothing to do here.
    if (!isset($element['#value']['aid'])) {
      if (!empty($element['#value']['fids'])) {
        throw new \RuntimeException('Unexpected FID without sermon audio ID.');
      }
      return $element;
    }

    // Go ahead and tokenize it and put it on the form. This way, when the user
    // submits the form later, we can re-use that sermon audio ID.
    $aid = (int) $element['#value']['aid'];
    // See if we already have a token in the cache.
    $cachedAid =& static::getCacheReferenceForElement($element, 'aid', $formState);
    if ($cachedAid !== NULL && $cachedAid !== $aid) {
      throw new \RuntimeException('Sermon audio ID does not match cached value.');
    }
    $token =& static::getCacheReferenceForElement($element, 'aid_token', $formState);
    if ($token === NULL || $cachedAid === NULL) {
      $token = static::tokenizeAid($aid);
      if ($cachedAid === NULL) {
        $cachedAid = $aid;
      }
    }
    $element['aid_token'] = [
      '#type' => 'hidden',
      '#value' => $token,
    ];

    // Grab the render array associated with displaying the filename.
    // @see \Drupal\file\Element\ManagedFile::processManagedFile()
    $fids = $element['#value']['fids'];
    $fid = (int) reset($fids);
    $filenameElement =& $element['file_' . $fid]['filename'];
    // If we have an unprocessed audio file, we don't want to show a link to the
    // file (we just want plain text). Also, add something indicating that the
    // audio is unprocessed.
    if (!$element['#value']['processed']) {
      // The newlines are to ensure there is whitespace (one space) on either
      // side of the text.
      $suffix = "\n<span>(unprocessed)</span>\n";
      if (isset($filenameElement['#suffix'])) $filenameElement['#suffix'] .= $suffix;
      else $filenameElement['#suffix'] = $suffix;

      $filenameElement['#sermon_audio_suppress_link'] = TRUE;
    }

    return $element;
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
   * A reference is returned even if none presently exists. In this case, a
   * value is created and set to NULL, and a reference to that value returned.
   *
   * @param array $element
   *   Widget element.
   * @param string $key
   *   Key.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   *
   * @throws \RuntimeException
   *   Can be thrown if $element contains a bad #parent value.
   */
  private static function &getCacheReferenceForElement(array $element, string $key, FormStateInterface $formState) : mixed {
    $fullKey = 'sermon_audio.' . static::getElementKey($element) . '.' . $key;
    if (!$formState->hasTemporaryValue($fullKey)) {
      $formState->setTemporaryValue($fullKey, NULL);
    }
    return $formState->getTemporaryValue($fullKey);
  }

  /**
   * Gets a key, unique within form, associated with given widget element array.
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
      // Assume we are at the root level.
      return $key;
    }
    if (!is_array($element['#parents'])) {
      throw new \RuntimeException('A widget element had invalid an invalid #parents key.');
    }
    $firstTime = TRUE;
    $escapeChars = [StringHelpers::ASCII_UNIT_SEPARATOR];
    foreach ($element['#parents'] as $parent) {
      if (!$firstTime) {
        $key .= StringHelpers::ASCII_UNIT_SEPARATOR;
      }
      $key .= StringHelpers::escape((string) $parent, $escapeChars);
      $firstTime = FALSE;
    }

    return $key;
  }

  /**
   * Gets the file entity storage using the global service container.
   */
  private static function getFileStorage() : FileStorageInterface {
    return \Drupal::entityTypeManager()->getStorage('file');
  }

  /**
   * Validates and gets the unprocessed audio upload location.
   *
   * Module settings are retrieved statically, not using DI.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the upload location (pulled from the module configuration) is
   *   empty.
   */
  private static function getUploadLocationStatically() : string {
    $uploadLocation = Settings::getUnprocessedAudioUriPrefix();
    if ($uploadLocation === '') {
      throw new ModuleConfigurationException('The unprocessed_audio_uri_prefix setting is empty.');
    }
    return $uploadLocation;
  }

  /**
   * Stores given sermon audio ID in a key-value store with random token.
   *
   * This function generates a token corresponding to the given sermon audio ID
   * and stores the ID with the given expiry time.
   *
   * @param int $aid
   *   Sermon audio ID.
   * @param int $expiry
   *   The number of seconds for which the sermon ID / token combination should
   *   be valid. Should be positive. Defaults to 24 hours = 60 * 60 * 24
   *   seconds.
   *
   * @return string
   *   Associated token.
   */
  private static function tokenizeAid(int $aid, int $expiry = 86400) : string {
    assert($expiry > 0);

    $token = base64_encode(random_bytes(12));
    $store = \Drupal::keyValueExpirable('sermon_audio.sermon_audio_ids');
    $store->setWithExpire($token, $aid, $expiry);

    return $token;
  }

}
