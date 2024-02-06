<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\sermon_audio\QueueItem\RefreshJob;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Refreshes processed audio / transcription for certain sermon audio entities.
 *
 * @QueueWorker(
 *   id = "sermon_audio_entity_refresher",
 *   title = @Translation("Sermon Audio Entity Refresher"),
 *   cron = {"time" = 60}
 * )
 */
class EntityRefresherQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   *   Sermon audio entity storage.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityStorageInterface $sermonAudioStorage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->sermonAudioStorage = $sermonAudioStorage;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(mixed $data) : void {
    try {
      if (!($data instanceof RefreshJob)) {
        throw new \InvalidArgumentException('Queue data is not an instance of \\Drupal\\sermon_audio\\QueueItem\\RefreshJob.');
      }
      $data->processItem($this->sermonAudioStorage);
    }
    catch (\Exception $e) {
      // Mark the item for immediate re-queing, because we want to make sure
      // our hook_cron() implementation can delete it later.
      throw new RequeueException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, mixed $plugin_id, mixed $plugin_definition) : self {
    $entityTypeManager = $container->get('entity_type.manager');
    assert($entityTypeManager instanceof EntityTypeManagerInterface);
    return new self($configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager->getStorage('sermon_audio'));
  }

}
