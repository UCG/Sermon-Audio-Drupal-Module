<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\sermon_audio\Entity\SermonAudio;

// @todo Fix render array caching issue.

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

      // Output the processed audio field if it's set. Force the use of the
      // "rendered entity" view type (with no label), because otherwise a link
      // and a label will instead be shown.
      $output[$delta] = $sermonAudio->get('processed_audio')?->view([
        'type' => 'entity_reference_entity_view',
        'label' => 'hidden',
        'weight' => 0,
      ]) ?? [];
      // @todo See if cache tags must be attached.
    }

    return $output;
  }

}
