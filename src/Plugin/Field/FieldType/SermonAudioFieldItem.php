<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldType;

use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Ranine\Helper\ParseHelpers;

/**
 * Represents a field linking to a sermon audio entity.
 *
 * @FieldType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   default_formatter = "sermon_audio",
 *   default_widget = "sermon_audio",
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
   * Validates / converts to int the given form element's upload maximum size.
   *
   * An error is set if $element['#value'] is neither unset, nor NULL, nor an
   * empty string, nor a positive, integral value.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function validateAndPrepareUploadMaxFileSize($element, FormStateInterface $form_state) : void {
    if (!isset($element['#value'])) return;

    $unparsedValue = $element['#value'];
    if ($unparsedValue === '' || $unparsedValue === NULL) return;

    $value = 0;
    if (!ParseHelpers::tryParseInt($unparsedValue, $value)) {
      $form_state->setError($element, t('The maximum file size provided is non-integral.'));
    }
    if ($value <= 0) {
      $form_state->setError($element, t('The maximum file size provided is not positive.'));
    }

    // Set the form element value to the integer we just parsed to avoid having
    // to do that later.
    $form_state->setValueForElement($element, $value);
  }

  /**
   * Validates the given form element's value as a list of extensions.
   *
   * An error is set if the string value of $element['#value'] is not a
   * space-separated list of extensions, each having only alphanumeric
   * characters, "_", "." and/or "-".
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @throws \RuntimeException
   *   Thrown if a RegEx matching attempt fails.
   */
  public static function validateUploadFileExtensions($element, FormStateInterface $form_state) : void {
    if (!isset($element['#value'])) return;

    $extensions = (string) $element['#value'];
    if ($extensions === "") {
      return;
    }

    $matchResult = preg_match('/^[a-z0-9_\-.]+(?: [a-z0-9_\-.]+)*$/i', $extensions);
    if ($matchResult === FALSE) {
      throw new \RuntimeException('Regex error.');
    }
    if ($matchResult !== 1) {
      $form_state->setError($element, t('The extensions list provided is invalid.'));
    }
  }

}
