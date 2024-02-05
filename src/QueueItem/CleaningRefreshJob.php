<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\QueueItem;

use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\RefreshHelpers;

class CleaningRefreshJob extends RefreshJob {

  /**
   * Processes the given newly loaded sermon audio entity.
   *
   * For further exception information, @see \Drupal\sermon_audio\Entity\SermonAudio::refreshProcessedAudio().
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when saving the entity.
   */
  protected function processEntity(SermonAudio $entity) : void {
    if (RefreshHelpers::refreshProcessedAudioAllTranslations($entity)) {
      $entity->save();
    }
  }

}
