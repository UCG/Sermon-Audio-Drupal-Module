<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Formatter for sermon audio fields.
 *
 * @FieldFormatter(
 *   id = "sermon_processed_audio",
 *   label = @Translation("Sermon Processed Audio"),
 *   field_types = { "sermon_audio" },
 * )
 */
class SermonAudioFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $output = [];
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $sermonAudio) {
      assert($sermonAudio instanceof SermonAudio);

      // Output the processed audio field if it's set.
      $output[$delta] = $sermonAudio->get('processed_audio')?->view() ?? [];
      // @todo During testing, see if cache tags must be attached.
    }

    return $output;
  }

}
