<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\RefreshHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Refreshes processed audio for certain sermon audio entities.
 *
 * @QueueWorker(
 *   id = "sermon_audio_processed_audio_refresher",
 *   title = @Translation("Sermon Audio Processed Audio Refresher"),
 *   cron = {"time" = 60}
 * )
 */
class AudioRefresherQueueWorker extends EntityRefresherQueueWorker {

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save the entity.
   */
  protected function processEntity(SermonAudio $entity) : ?callable {
    if (RefreshHelpers::refreshProcessedAudioAllTranslations($entity)) {
      $entity->save();
    }
    return NULL;
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
