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
 * Note that the list class for this field type is set to the entity reference
 * list class: this is done so that \Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase::getEntitiesToView()
 * can be called in the default field formatter.
 *
 * @FieldType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   category = @Translation("Reference"),
 *   default_formatter = "sermon_audio_player",
 *   default_widget = "sermon_unprocessed_audio",
 *   list_class = "\Drupal\Core\Field\EntityReferenceFieldItemList",
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
      '#description' => t('Maximum uploaded unprocessed audio size, in bytes. If this is not present or is greater than the PHP limit, the PHP maximum upload limit applies.'),
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
   *   a managed file element's '#upload_validators' property. Includes at least
   *   specifications for the "file_validate_extensions" validator.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if a relevant field setting does not exist or is invalid.
   */
  public function getUploadValidators() : array {
    return static::getUploadValidatorsForSettings($this->getSettings());
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) : array {
    return [];
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
   * Returns unprocessed audio upload validators for the given field settings.
   *
   * @param array $settings
   *   Field settings.
   *
   * @return array[]
   *   Validator specifications, suitable for passing to file_save_upload() or
   *   a managed file element's '#upload_validators' property. Includes at least
   *   specifications for the "file_validate_extensions" validator.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if a relevant field setting does not exist or is invalid.
   */
  public static function getUploadValidatorsForSettings(array $settings) : array {
    $validators = [];

    $validators['file_validate_size'] = [static::getMaxUploadFileUploadSize($settings)];
    $validators['file_validate_extensions'] = [static::getAllowedUploadFileExtensions($settings)];

    return $validators;
  }

  /**
   * Validates / massages the given form element's upload maximum size.
   *
   * If $element['#value'] is an empty string, the upload maximum size is set to
   * NULL. An error is set if $element['#value'] is neither unset, nor NULL, nor
   * an empty string, nor a positive, integral value. If $element['#value'] is a
   * positive, integral value, the upload maximum size is set to
   * $element['#value'] after it is converted to an integer.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   */
  public static function validateAndPrepareUploadMaxFileSize(array $element, FormStateInterface $formState) : void {
    if (!isset($element['#value'])) return;

    $unparsedValue = $element['#value'];
    if ($unparsedValue === '') return;

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
   * An error is set if $element['#value'] isn't set, or if its string value is
   * not a space-separated list of extensions, each having only alphanumeric
   * characters, "_", "." and/or "-". An error is also set if
   * (string) $element['#value'] is empty or consists only of whitespace.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   Form state.
   *
   * @throws \RuntimeException
   *   Thrown if a regex matching attempt fails.
   */
  public static function validateUploadFileExtensions(array $element, FormStateInterface $formState) : void {
    if (!isset($element['#value'])) {
      $formState->setError($element, t('There is no extensions list value.'));
      return;
    }

    $extensions = (string) $element['#value'];
    if (trim($extensions) === "") {
      $formState->setError($element, t('The extensions list provided is empty or consists only of whitespace.'));
      return;
    }

    $matchResult = preg_match('/^[a-z0-9_\-.]+(?: [a-z0-9_\-.]+)*$/i', $extensions);
    if ($matchResult === FALSE) {
      throw new \RuntimeException('Regex error.');
    }
    if ($matchResult !== 1) {
      $formState->setError($element, t('The extensions list provided is invalid.'));
    }
  }

  /**
   * Gets the allowed upload file extensions from the field settings.
   *
   * @param array $settings
   *   Field settings.
   *
   * @phpstan-return non-empty-string
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if the upload_file_extensions field setting does not exist, or is
   *   empty after being converted to a string and trimmed.
   */
  private static function getAllowedUploadFileExtensions(array $settings) : string {
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
   * @param array $settings
   *   Field settings.
   *
   * @return int
   *   Maximum upload size, in bytes. Retrieves from field settings, if the
   *   relevant setting is set and less than the PHP max value -- else the PHP
   *   max value is used.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidFieldConfigurationException
   *   Thrown if the upload_max_file_size field setting is set, but is
   *   non-integral or non-positive.
   */
  private static function getMaxUploadFileUploadSize(array $settings) : int {
    if (isset($settings['upload_max_file_size'])) {
      $maxUploadSize = $settings['upload_max_file_size'];
      if ($maxUploadSize !== NULL) {
        if (!is_int($maxUploadSize)) {
          throw new InvalidFieldConfigurationException('Sermon audio field setting upload_max_file_size is not an integer.');
        }
        if ($maxUploadSize <= 0) {
          throw new InvalidFieldConfigurationException('Sermon audio field setting upload_max_file_size is non-positive.');
        }
        return min($maxUploadSize, (int) Environment::getUploadMaxSize());
      }
    }

    return (int) Environment::getUploadMaxSize();
  }

}
