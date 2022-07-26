<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Template\Attribute;
use Drupal\file\Plugin\Field\FieldFormatter\FileMediaFormatterBase;
use Ranine\Iteration\ExtendableIterable;

/**
 * File field audio formatter that allows MP4 files.
 *
 * @FieldFormatter(
 *   id = "mp4_enabled_audio_file",
 *   label = @Translation("MP4-Enabled Audio"),
 *   description = @Translation("Displays the file using an HTML5 audio tag. Supports MP4 audio files."),
 *   field_types = { "file" },
 * )
 */
class Mp4EnabledAudioFileFieldFormatter extends FileMediaFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) : array {
    $elements = parent::viewElements($items, $langcode);
    // The base class uses the plugin ID as the theme for each item. We want to
    // use the file_audio theme instead.
    foreach ($elements as &$element) {
      $element['#theme'] = 'file_audio';
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function getMediaType() {
    return 'audio';
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $fieldDefinition) : bool {
    return ExtendableIterable::from(explode(' ', $fieldDefinition->getSetting('file_extensions')))
      ->map(fn($k, $v) => trim((string) $v))
      ->any(fn($k, $v) => strcasecmp($v, 'mp4') === 0)
      || parent::isApplicable($fieldDefinition);
  }

}
