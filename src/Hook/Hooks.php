<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactoryInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\file\FileInterface;
use Drupal\views\FieldViewsDataProvider;

class Hooks {

  private readonly QueueInterface $processedAudioRefresherQueue;
  private readonly EntityStorageInterface $sermonAudioStorage;
  private readonly QueueInterface $transcriptionRefresherQueue;

  public function __construct(private readonly FieldViewsDataProvider $fieldViewsDataProvider,
    EntityTypeManagerInterface $entityTypeManager,
    QueueFactoryInterface $queueFactory)
  {
    $this->sermonAudioStorage = $entityTypeManager->getStorage('sermon_audio');
    $this->processedAudioRefresherQueue = $queueFactory->get('sermon_audio_processed_audio_refresher');
    $this->transcriptionRefresherQueue = $queueFactory->get('sermon_audio_transcription_refresher');
  }

  /**
   * Add "sermon_audio_suppress_link" var to "file_link" theme hook variables.
   */
  #[Hook('theme_registry_alter')]
  public function addSuppressLinkThemeVariable(array &$registry) : void {
    if (!empty($registry['file_link'])) {
      assert(is_array($registry['file_link']));
      $registry['file_link']['variables']['sermon_audio_suppress_link'] = NULL;
    }
  }

  /**
   * Adds a Views relationship for the target sermon audio entity.
   */
  #[Hook('field_views_data')]
  public function provideSermonAudioViewsRelationship(FieldStorageConfigInterface $field_storage) : array {
    $data = $this->fieldViewsDataProvider->defaultFieldImplementation($field_storage);

    if ($field_storage->getType() != 'sermon_audio') {
      return $data;
    }
    if ($field_storage->getSetting('target_type') != 'sermon_audio') {
      // Something is wrong...
      return $data;
    }

    $fieldName = $field_storage->getName();
    $args = ['@field_name' => $fieldName];
    foreach ($data as $tableName => $d) {
      $data[$tableName][$fieldName]['relationship'] = [
        'title' => t('Sermon Audio referenced from @field_name', $args),
        'label' => t('@field_name: Sermon Audio', $args),
        'group' => t('Sermon Audio'),
        'help' => t('Appears in: @bundles.', ['@bundles' => implode(', ', $field_storage->getBundles())]),
        'id' => 'standard',
        'base' => 'sermon_audio_field_data',
        'base field' => 'id',
        'relationship field' => ($fieldName . '_target_id'),
      ];
    }

    return $data;
  }

  #[Hook('theme')]
  public function provideThemeHooks() : array {
    return [
      'sermon_audio_player_no_processed_audio' => [
        'template' => 'sermon-audio-player-no-processed-audio',
        'variables' => [],
      ],
      'sermon_audio_player_broken_processed_audio' => [
        'template' => 'sermon-audio-player-broken-processed-audio',
        'variables' => [],
      ],
      'sermon_audio_link_no_processed_audio' => [
        'template' => 'sermon-audio-link-no-processed-audio',
        'variables' => [],
      ],
      'sermon_audio_link_broken_processed_audio' => [
        'template' => 'sermon-audio-link-broken-processed-audio',
        'variables' => [],
      ],
    ];
  }

  /**
   * Automatically refresh sermon audio and transcriptions wherever applicable.
   */
  #[Hook('cron')]
  public function refreshAudioAndTranscriptions() : void {
    $idsForAudioRefresh = $this->sermonAudioStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('cleaning_job_id', NULL, 'IS NOT NULL')
      ->condition('cleaning_job_id', '', '<>')
      ->condition('cleaning_job_failed', TRUE, '<>')
      ->execute();
    assert(is_array($idsForAudioRefresh));
    $idsForTranscriptionRefresh = $this->sermonAudioStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('transcription_job_id', NULL, 'IS NOT NULL')
      ->condition('transcription_job_id', '', '<>')
      ->condition('transcription_job_failed', TRUE, '<>')
      ->execute();
    assert(is_array($idsForTranscriptionRefresh));

    if ($idsForAudioRefresh !== []) {
      // Clean unused items from the queue, as there is no built-in mechanism to
      // check if an entity ID already exists in the queue.
      while ($item = $this->processedAudioRefresherQueue->claimItem()) {
        $this->processedAudioRefresherQueue->deleteItem($item);
      }

      foreach ($idsForAudioRefresh as $id) {
        $this->processedAudioRefresherQueue->createItem((int) $id);
      }
    }

    if ($idsForTranscriptionRefresh !== []) {
      while ($item = $this->transcriptionRefresherQueue->claimItem()) {
        $this->transcriptionRefresherQueue->deleteItem($item);
      }

      foreach ($idsForTranscriptionRefresh as $id) {
        $this->transcriptionRefresherQueue->createItem((int) $id);
      }
    }
  }

  /**
   * Remove file_link theme link to file if sermon_audio_suppress_link is set.
   *
   * Implements hook_preprocess_HOOK() for file_link.
   */
  #[Hook('preprocess_file_link')]
  public function suppressFileLinkDuringRendering(array &$variables) : void {
    if (!empty($variables['sermon_audio_suppress_link'])) {
      $file = $variables['file'];
      if (!($file instanceof FileInterface)) {
        throw new \RuntimeException('Invalid file_link theme hook #file variable.');
      }
      // In this case, we don't want to show an actual link to the file. Hence,
      // override the "link" variable with the sanitized plain-text filename (it
      // should be sanitized by Twig automatically, but we do so here just in case
      // a template is used which suppresses this sanitization, etc).
      $variables['link'] = Html::escape($file->getFilename() ?? '');
    }
  }

}
