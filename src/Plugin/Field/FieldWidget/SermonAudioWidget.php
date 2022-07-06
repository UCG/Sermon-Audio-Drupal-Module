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
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\Element\ManagedFile;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\FileRenamePseudoExtensionRepository;
use Drupal\sermon_audio\Plugin\Field\FieldType\SermonAudioFieldItem;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ParseHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit widget for sermon audio fields.
 *
 * id = "sermon_unprocessed_audio",
 * label = @Translation("Sermon Unprocessed Audio"),
 * field_types = { "sermon_audio" },
 */
class SermonAudioWidget extends WidgetBase {

  /** Some of the code herein is adapted from
   * @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget */

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
   */
  protected function __construct(string $pluginId,
    $pluginDefinition,
    FieldDefinitionInterface $fieldDefinition,
    array $settings,
    array $thirdPartySettings,
    TranslationInterface $translationManager,
    ConfigFactoryInterface $configFactory,
    FileRenamePseudoExtensionRepository $renamePseudoExtensionRepo,
    EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($pluginId, $pluginDefinition, $fieldDefinition, $settings, $thirdPartySettings);
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

    $uploadValidators = $fieldItem->getUploadValidators();
    if ($this->getSetting('auto_rename')) {
      // Use a random bare filename (filename without extension) to ensure
      // uniqueness.
      $bareFilename = hex2bin(random_bytes(8));
      // Add an extra allowed extension to the "file_validate_extensions"
      // validator settings. This is not actually a relevant extension (in fact,
      // we should never get a file with this extension, given that it is a long
      // random hexadecimal string), but we include it to signal that we want to
      // rename the file. We associate this extension with the bare filename
      // above.
      // @see \Drupal\sermon_audio\FileRenamePseudoExtensionRepository
      $pseudoExtension = $this->renamePseudoExtensionRepo->addBareFilename($bareFilename);
      $settings =& $uploadValidators['file_validate_extensions'];
      $settings[array_key_first($settings)] .= ' ' . $pseudoExtension;
    }
    $element = [
      '#type' => 'managed_file',
      '#progress_indicator' => $this->getSetting('progress_indicator'),
      '#upload_location' => $this->getUploadLocation(),
      '#upload_validators' => $uploadValidators,
      '#value_callback' => [static::class, 'getWidgetValue'],
      // Ensure that we can encode other information in #value besides that
      // handled/returned by the managed_file form element.
      '#extended' => TRUE,
    ] + $element;

    $targetId = $fieldItem->get('target_id');
    if ($targetId === '' || $targetId === NULL) { 
      $sermonAudioId = NULL;
      $processedAudioFid = NULL;
    }
    else {
      $sermonAudioId = (int) $targetId;
      $sermonAudio = $this->sermonAudioStorage->load($sermonAudioId);
      assert($sermonAudio instanceof SermonAudio);
      $processedAudioFid = $sermonAudio->getProcessedAudioFid();
    }

    $element['#default_value'] = [
      'aid' => $sermonAudioId,
      'fids' => [$processedAudioFid],
      'processed' => TRUE,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) : array {
    foreach ($values as &$value) {
      if (is_array($value)) {
        if (!array_key_exists('aid', $value) || !is_int($value['aid'])) {
          throw new \RuntimeException('Invalid or missing sermon audio ID in widget form value.');
        }
        $value = ['target_id' => $value['aid']];
      }
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
   * @param array $element
   *   Form element.
   * @param mixed $input
   *   Value previously computed from user input, or FALSE to indicate that the
   *   element's default value should be returned.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   *
   * @return array
   *   Widget element value.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs while saving an entity.
   * @throws \Ranine\Exception\ParseException
   *   Thrown if an error occurs when attempting to parse the input FID.
   * @throws \RuntimeException
   *   Thrown in some cases if $input is invalid (we don't throw an
   *   \InvalidArgumentException because of how this function is called...).
   */
  public static function getWidgetValue(array &$element, $input, FormStateInterface $formState) : array {
    // To start, let the managed_file form element compute a value.
    // The $input value may contain some extra stuff that the managed_file
    // callback doesn't need, and that might mess up our calculations later.
    // Thus, we strip everything except the FID out. Save the input first for
    // use later.
    $originalInput = $input;
    if (is_array($input)) {
      if (array_key_exists('fids', $input)) {
        // We should never have more than one FID.
        $originalFidString = trim((string) $input['fids']);
        if ($originalFidString === '') {
          $input = [];
        }
        else {
          $originalFid = ParseHelpers::parseIntFromString($originalFidString);
          $input = ['fids' => $originalFidString];
        }
      }
      else $input = [];
    }
    $value = ManagedFile::valueCallback($element, $input, $formState);
    // If the form element produced the default value (referencing the processed
    // audio file), we don't want to mess with it. Otherwise, we have to figure
    // out what sermon audio ID to attach.
    if (empty($value['processed'])) {
      // If the FID has changed, we create a new sermon audio entity for the new
      // unprocessed audio file. If it hasn't changed, we re-use the current
      // sermon audio ID (which must exist).
      // Now, we don't need or want a sermon audio entity if there is no file.
      if (isset($value['fids']) && $value['fids'] !== []) {
        $value['processed'] = FALSE;
        $newFid = (int) reset($value['fids']);
        if (!isset($originalFid) || ($newFid !== $originalFid)) {
          $value['aid'] = static::createSermonAudioFromUnprocessedFid($newFid);
        }
        else {
          // Use the old value for the audio ID.
          assert(is_array($originalInput));
          if (!array_key_exists('aid', $originalInput)) {
            throw new \RuntimeException('Invalid input value.');
          }
          $value['aid'] = $originalInput['aid'];
        }
      }
    }

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

}
