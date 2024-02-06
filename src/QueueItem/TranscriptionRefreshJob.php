<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\QueueItem;

use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\RefreshHelpers;

class TranscriptionRefreshJob extends RefreshJob {

  /**
   * Creates a new audio transcription refresh job.
   *
   * @param int $entityId
   *   ID of sermon audio entity whose transcription should be refreshed.
   */
  public function __construct(int $entityId) {
    parent::__construct($entityId);
  }

  /**
   * Processes the given newly loaded sermon audio entity.
   *
   * For further exception information, @see \Drupal\sermon_audio\Entity\SermonAudio::refreshTranscription().
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when saving the entity.
   */
  protected function processEntity(SermonAudio $entity) : void {
    if (RefreshHelpers::refreshTranscriptionAllTranslations($entity)) {
      $entity->save();
    }
  }

}
