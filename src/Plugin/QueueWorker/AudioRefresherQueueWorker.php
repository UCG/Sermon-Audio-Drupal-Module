<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\AudioHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Automatically refreshes processed audio for certain sermon audio entities.
 *
 * @QueueWorker(
 *   id = "sermon_audio_refresher",
 *   title = @Translation("Sermon Audio Refresher"),
 *   cron = {"time" = 60}
 * )
 */
class AudioRefresherQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Sermon audio entity storage.
   */
  private EntityStorageInterface $sermonAudioStorage;

  /**
   * Creates a new audio refresher queue worker.
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
  public function processItem($data) {
    try {
      if (!is_int($data) || $data < 0) {
        throw new \InvalidArgumentException('Queue data is not a nonnegative integer.');
      }

      // $data is the entity ID.
      $entity = $this->sermonAudioStorage->load($data);
      if ($entity === NULL) return;
      assert($entity instanceof SermonAudio);

      if (AudioHelper::refreshProcessedAudioAllTranslations($entity)) {
        $entity->save();
      }
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : static {
    $entityTypeManager = $container->get('entity_type.manager');
    assert($entityTypeManager instanceof EntityTypeManagerInterface);
    return new self($configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager->getStorage('sermon_audio'));
  }

}
