<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\RefreshHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Refreshes transcription sub-key for certain sermon audio entities.
 *
 * @QueueWorker(
 *   id = "sermon_audio_transcription_refresher",
 *   title = @Translation("Sermon Audio Transcription Refresher"),
 *   cron = {"time" = 60}
 * )
 */
class TranscriptionRefresherQueueWorker extends EntityRefresherQueueWorker {

  private readonly EventDispatcherInterface $eventDispatcher;

  /**
   * Creates a new sermon audio transcription sub-key refresher queue worker.
   *
   * @param array $configuration
   *   Plugin instance configuration.
   * @param string $plugin_id
   *   Plugin ID for plugin instance.
   * @param mixed $plugin_definition
   *   Plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $sermonAudioStorage
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   */
  public function __construct(array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityStorageInterface $sermonAudioStorage,
    EventDispatcherInterface $eventDispatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $sermonAudioStorage);

    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save the entity.
   */
  protected function processEntity(SermonAudio $entity) : ?callable {
    /** @var ?callable (\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher) : void */
    $dispatching = NULL;
    if (RefreshHelpers::refreshTranscriptionAllTranslations($entity, $dispatching)) {
      $entity->save();
      assert($dispatching !== NULL);
      return function () use ($dispatching) : void {
        $dispatching($this->eventDispatcher);
      };
    }
    else return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, mixed $plugin_id, mixed $plugin_definition) : self {
    $entityTypeManager = $container->get('entity_type.manager');
    assert($entityTypeManager instanceof EntityTypeManagerInterface);
    $eventDispatcher = $container->get('event_dispatcher');
    assert($eventDispatcher instanceof EventDispatcherInterface);
    return new self($configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager->getStorage('sermon_audio'),
      $eventDispatcher);
  }

}
