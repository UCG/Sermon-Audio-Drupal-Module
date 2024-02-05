<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\QueueItem;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\sermon_audio\Entity\SermonAudio;

abstract class RefreshJob {

  /**
   * ID of sermon audio entity to refresh.
   */
  private readonly int $entityId;

  /**
   * Creates a new refresh job.
   *
   * @param int $entityId
   *   ID of sermon audio entity to refresh.
   */
  protected function __construct(int $entityId) {
    $this->entityId = $entityId;
  }

  /**
   * Processes this queue item.
   *
   * If the sermon audio entity is not found, nothing is done.
   *
   * Exceptions thrown depend on the subclass implementation of
   * @see \Drupal\sermon_audio\QueueItem\RefreshJob::processEntity().
   */
  public function processItem(EntityStorageInterface $sermonAudioStorage) : void {
    $entity = $sermonAudioStorage->load($this->entityId);
    if ($entity === NULL) return;
    $this->processEntity($entity);
  }

  /**
   * Processes the given newly loaded sermon audio entity.
   */
  protected abstract function processEntity(SermonAudio $entity) : void;

}
