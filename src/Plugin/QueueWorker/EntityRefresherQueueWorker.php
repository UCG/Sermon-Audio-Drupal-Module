<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Base class for processed audio / transcription refresher queue workers.
 */
abstract class EntityRefresherQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  private readonly EntityStorageInterface $sermonAudioStorage;

  /**
   * Creates a new sermon audio entity refresher queue worker.
   *
   * @param array $configuration
   *   Plugin instance configuration.
   * @param string $plugin_id
   *   Plugin ID for plugin instance.
   * @param mixed $plugin_definition
   *   Plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $sermonAudioStorage
   */
  protected function __construct(array $configuration, $plugin_id, $plugin_definition, EntityStorageInterface $sermonAudioStorage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->sermonAudioStorage = $sermonAudioStorage;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(mixed $data) : void {
    try {
      // $data should be the entity ID.
      if (!is_int($data)) {
        throw new \InvalidArgumentException('Queue data is not an integer');
      }

      try {
        // Before loading, ensure we have no "dual invocation" of sermon audio
        // refreshes (this can happen during load, and also during save, as
        // saving can call loadUnchanged()).
        SermonAudio::disablePostLoadAutoRefreshes($data);

        $entity = $this->sermonAudioStorage->load($data);

        // Ignore nonexistent entities.
        if ($entity === NULL) return;
        assert($entity instanceof SermonAudio);
        $this->processEntity($entity);
      }
      finally {
        SermonAudio::enablePostLoadAutoRefreshes($data);
      }
    }
    catch (\Exception $e) {
      // Mark the item for immediate re-queing, because we want to make sure
      // our hook_cron() implementation can delete it later.
      throw new RequeueException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Processes the given sermon audio entity.
   */
  protected abstract function processEntity(SermonAudio $entity) : void;

}
