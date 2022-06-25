<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldWidget;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Plugin\Field\FieldType\SermonAudioFieldItem;
use Ranine\Exception\InvalidOperationException;
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
   */
  protected function __construct(string $pluginId,
    $pluginDefinition,
    FieldDefinitionInterface $fieldDefinition,
    array $settings,
    array $thirdPartySettings,
    TranslationInterface $translationManager,
    ConfigFactoryInterface $configFactory) {
    parent::__construct($pluginId, $pluginDefinition, $fieldDefinition, $settings, $thirdPartySettings);
    $this->moduleConfiguration = $configFactory->get('sermon_audio.settings');
    $this->stringTranslation = $translationManager;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) : array {
    $fieldItem = $items[$delta];
    if (!($fieldItem instanceof SermonAudioFieldItem)) {
      throw new InvalidOperationException('This method was called on a field with an item of an invalid type.');
    }

    $uploadValidators = $fieldItem->getUploadValidators();
    $element += [
      '#type' => 'managed_file',
      '#progress_indicator' => $this->getSetting('progress_indicator'),
      '#upload_location' => $this->getUploadLocation(),
      '#upload_validators' => $uploadValidators,
    ];

    $element['#default_value'] = $fieldItem->getValue();

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    $element['progress_indicator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Upload progress indicator'),
      '#options' => [
        'throbber' => $this->t('Throbber'),
        'bar' => $this->t('Progress bar'),
      ],
      '#default_value' => $this->getSetting('progress_indicator'),
      // file_progress_implementation() seems to indicate what and whether there
      // is functionality for measuring file upload status -- it should return
      // FALSE if no such functionality exists.
      '#access' => file_progress_implementation(),
    ];
    
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() : array {
    $summary = [];
    $summary[] = t('Progress indicator: @progress_indicator', ['@progress_indicator' => $this->getSetting('progress_indicator')]);
    return $summary;
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
      $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() : array {
    return ['progress_indicator' => 'throbber'] + parent::defaultSettings();
  }

}
