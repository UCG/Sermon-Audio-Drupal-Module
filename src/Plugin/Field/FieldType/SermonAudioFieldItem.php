<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldType;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sermon_audio\Exception\InvalidFieldConfigurationException;
use Ranine\Helper\ParseHelpers;

/**
 * Represents a field linking to a sermon audio entity.
 *
 * @FieldType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   default_formatter = "sermon_processed_audio",
 *   default_widget = "sermon_unprocessed_audio",
 * )
 */
class SermonAudioFieldItem extends EntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) : array {
    $element = [];
    $settings = $this->getSettings();

    $element['upload_file_extensions'] = [
      '#type' => 'textfield',
      '#title' => t('Allowed unprocessed audio file extensions'),
      '#default_value' => (string) $settings['upload_file_extensions'],
      '#description' => t('A list of extensions, separated by spaces. Each extension may contain alphanumeric characters, underscores ("_"), dashes ("-"), and/or periods (".").'),
      '#element_validate' => [[static::class, 'validateUploadFileExtensions']],
      '#weight' => 1,
      '#maxlength' => 256,
      // To ensure no one sets things up so that a file with any extension may
      // be uploaded (which might be a security problem), we force people to
      // provide a value for this setting.
      '#required' => TRUE,
    ];

    $element['upload_max_file_size'] = [
      '#type' => 'textfield',
      '#title' => t('Maximum unprocessed audio upload size'),
      '#default_value' => (int) $settings['upload_max_file_size'],
      '#description' => t('Maximum uploaded unprocessed audio size, in bytes. If this is not present or greater than the PHP limit, the PHP maximum upload limit applies.'),
      '#size' => 10,
      '#element_validate' => [[static::class, 'validateAndPrepareUploadMaxFileSize']],
      '#weight' => 2,
    ];

    return $element;
  }

  /**
   * Returns unprocessed audio upload validators for this field.
   *
   * @return array[]
   *   Validator specifications, suitable for passing to file_save_upload() or
   *   a managed file element's '#upload_validators' property.
   */
  public function getUploadValidators() {
    $validators = [];

    $validators['file_validate_size'] = [$this->getMaxUploadFileUploadSize()];
    $validators['file_validate_extensions'] = [$this->getAllowedUploadFileExtensions()];

    return $validators;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) : array {
    return [];
  }

  /**
   * Gets the allowed upload file extensions from the field settings.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if the upload_file_extensions field setting does not exist, or is
   *   empty after being converted to a string and trimmed.
   */
  private function getAllowedUploadFileExtensions() : string {
    $settings = $this->getSettings();
    if (!isset($settings['upload_file_extensions'])) {
      throw new InvalidFieldConfigurationException('The upload_file_extensions setting is not set.');
    }
    $extensions = trim((string) $settings['upload_file_extensions']);
    if ($extensions === '') {
      throw new InvalidFieldConfigurationException('The upload_file_extensions setting is empty after trimming.');
    }
    return $extensions;
  }

  /**
   * Gets the maximum upload file upload size, in bytes.
   *
   * Retrieves from field settings, if the relevant setting is set -- else uses
   * PHP max value.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if the upload_max_file_size field setting is set, but is
   *   non-integral or non-positive.
   */
  private function getMaxUploadFileUploadSize() : int {
    $settings = $this->getSettings();
    if (isset($settings['upload_max_file_size'])) {
      $maxUploadSize = $this->getSettings()['upload_max_file_size'];
      if ($maxUploadSize !== '') {
        if (!is_int($maxUploadSize)) {
          throw new InvalidFieldConfigurationException('Sermon audio field setting upload_max_file_size is not an integer.');
        }
        if ($maxUploadSize <= 0) {
          throw new InvalidFieldConfigurationException('Sermon audio field setting upload_max_file_size is non-positive.');
        }
        return $maxUploadSize;
      }
    }

    // Otherwise, return the PHP max upload size.
    return Environment::getUploadMaxSize();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() : array {
    return [
      'upload_file_extensions' => 'mp3 mp4 m4a aac wav pcm',
      'upload_max_file_size' => 1000000000,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() : array {
    return [
      'target_type' => 'sermon_audio',
      'display_field' => FALSE,
      'display_default' => FALSE,
    ] + parent::defaultStorageSettings();
  }

  /**
   * Validates / converts to int the given form element's upload maximum size.
   *
   * An error is set if $element['#value'] is neither unset, nor NULL, nor an
   * empty string, nor a positive, integral value.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   */
  public static function validateAndPrepareUploadMaxFileSize($element, FormStateInterface $formState) : void {
    if (!isset($element['#value'])) return;

    $unparsedValue = $element['#value'];
    if ($unparsedValue === '' || $unparsedValue === NULL) return;

    $value = 0;
    if (!ParseHelpers::tryParseInt($unparsedValue, $value)) {
      $formState->setError($element, t('The maximum file size provided is non-integral.'));
    }
    if ($value <= 0) {
      $formState->setError($element, t('The maximum file size provided is not positive.'));
    }

    // Set the form element value to the integer we just parsed to avoid having
    // to do that later.
    $formState->setValueForElement($element, $value);
  }

  /**
   * Validates the given form element's value as a list of extensions.
   *
   * An error is set if the string value of $element['#value'] is not a
   * space-separated list of extensions, each having only alphanumeric
   * characters, "_", "." and/or "-". An error is also set if
   * (string) $element['#value'] is empty or consists only of whitespace.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   *
   * @throws \RuntimeException
   *   Thrown if a RegEx matching attempt fails.
   */
  public static function validateUploadFileExtensions($element, FormStateInterface $formState) : void {
    if (!isset($element['#value'])) return;

    $extensions = (string) $element['#value'];
    if (trim($extensions) === "") {
      $formState->setError($element, t('The extensions list provided is empty or consists only of whitespace.'));
    }
    else {
      $matchResult = preg_match('/^[a-z0-9_\-.]+(?: [a-z0-9_\-.]+)*$/i', $extensions);
      if ($matchResult === FALSE) {
        throw new \RuntimeException('Regex error.');
      }
      if ($matchResult !== 1) {
        $formState->setError($element, t('The extensions list provided is invalid.'));
      }
    }
  }

}
