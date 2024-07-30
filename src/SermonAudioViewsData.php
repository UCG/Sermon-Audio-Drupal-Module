<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\views\EntityViewsData;

/**
 * Provides views data for the sermon audio entity type.
 */
class SermonAudioViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() : array {
    $data = parent::getViewsData();
    $data['sermon_audio_field_data']['processed_audio__target_id']['relationship'] = [
      'title' => $this->t('Processed audio file'),
      'help' => $this->t('Processed audio file entity referenced by sermon audio entity.'),
      'base' => 'file_managed',
      'base field' => 'fid',
      // ID of relationship handler plugin to use.
      'id' => 'standard',
      // Default label for relationship in the UI.
      'label' => $this->t('Processed audio file'),
    ];

    return $data;
  }

}
