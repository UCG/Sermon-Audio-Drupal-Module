<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\EventSubscriber;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Helper\RefreshHelpers;
use Ranine\Helper\StringHelpers;
use Ranine\Helper\ThrowHelpers;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles announced finished cleaning/transcription job.
 *
 * @see \Drupal\sermon_audio\Controller\AnnouncementController
 */
class FinishedJobProcessor implements EventSubscriberInterface {

  /**
   * Name of static var (acc. w/ drupal_static()) indicating job ID.
   *
   * @var string
   */
  private const JOB_ID_VARIABLE_NAME = 'sermon_audio_announced_finished_job_id';

  /**
   * Name of static var (accessed w/ drupal_static()) indicating job type.
   *
   * TRUE for transcription, FALSE for cleaning.
   *
   * @var string
   */
  private const IS_TRANSCRIPTION_JOB_VARIABLE_NAME = 'sermon_audio_is_announced_finished_job_transcription_job';

  private readonly EventDispatcherInterface $eventDispatcher;

  /**
   * Storage for sermon audio entities.
   */
  private readonly EntityStorageInterface $sermonAudioStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EventDispatcherInterface $eventDispatcher) {
    $this->sermonAudioStorage = $entityTypeManager->getStorage('sermon_audio');
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Handles announced finished job after the response is sent.
   *
   * NOTE: For exception information,
   * @see \Drupal\sermon_audio\Entity\SermonAudio::refreshTranscription() and
   * @see \Drupal\sermon_audio\Entity\SermonAudio::refreshProcessedAudio().
   */
  public function handleKernelTerminate() : void {
    /** @var ?string */
    $jobId = NULL;
    $isTranscriptionJob = FALSE;
    self::getPostResponseJob($jobId, $isTranscriptionJob);
    if (StringHelpers::isNullOrEmpty($jobId)) return;

    if ($isTranscriptionJob) {
      $entityIds = $this->sermonAudioStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('transcription_job_id', $jobId)
        ->execute();
      // We don't want "double invocation" of the transcription refresh or
      // anything like that:
      foreach ($entityIds as $id) {
        SermonAudio::disablePostLoadAutoRefreshes((int) $id);
      }

      /** @var \Drupal\sermon_audio\Entity\SermonAudio[] */
      $entities = $this->sermonAudioStorage->loadMultiple($entityIds);

      foreach ($entityIds as $id) {
        SermonAudio::enablePostLoadAutoRefreshes((int) $id);
      }

      /** @var (callable(\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher) : void)[] */
      $dispatchings = [];
      foreach ($entities as $entity) {
        /** @var ?callable(\Symfony\Component\EventDispatcher\EventDispatcherInterface) */
        $dispatching = NULL;
        if (RefreshHelpers::refreshTranscriptionAllTranslations($entity, $dispatching)) {
          $entity->save();
          assert(is_callable($dispatching));
          $dispatchings[] = $dispatching;
        }
      }
      // We do all the dispatchings at once, so we don't have to worry as much
      // about them throwing exceptions.
      foreach ($dispatchings as $dispatching) {
        $dispatching($this->eventDispatcher);
      }
    }
    else {
      /** @var \Drupal\sermon_audio\Entity\SermonAudio[] */
      $entities = $this->sermonAudioStorage->loadByProperties(['cleaning_job_id' => $jobId]);
      foreach ($entities as $entity) {
        if (RefreshHelpers::refreshProcessedAudioAllTranslations($entity)) {
          $entity->save();
        }
      }
    }
  }

  /**
   * Sets the post-response job to the cleaning job with the given ID.
   *
   * @throws \InvalidArgumentException
   *   Thrown if $jobId is empty.
   */
  public function setJobAsCleaningJob(string $jobId) : void {
    ThrowHelpers::throwIfEmptyString($jobId, 'jobId');
    self::setPostResponseJob($jobId, FALSE);
  }

  /**
   * Sets the post-response job to the transcription job with the given ID.
   *
   * @throws \InvalidArgumentException
   *   Thrown if $jobId is empty.
   */
  public function setJobAsTranscriptionJob(string $jobId) : void {
    ThrowHelpers::throwIfEmptyString($jobId, 'jobId');
    self::setPostResponseJob($jobId, TRUE);
  }

  /**
   * Gets the information for the job that should be handled post-response.
   *
   * @param string $jobId
   *   (output) Job ID in repository, or NULL if there is no job.
   * @param bool $isTranscriptionJob
   *   (output) Job type in repository: TRUE for a transcription job, FALSE for
   *   a cleaning job.
   */
  private static function getPostResponseJob(?string &$jobId, bool &$isTranscriptionJob) : void {
    $jobId = drupal_static(self::JOB_ID_VARIABLE_NAME, NULL);
    $isTranscriptionJob = drupal_static(self::IS_TRANSCRIPTION_JOB_VARIABLE_NAME, FALSE);
  }

  /**
   * Sets the information for the job to be handled after the response.
   *
   * @param string $jobId
   *   Job ID that goes in the repository.
   * @param bool $isTranscriptionJob
   *   Job type: TRUE for a transcription job, FALSE for a cleaning job.
   */
  private static function setPostResponseJob(string $jobId, bool $isTranscriptionJob) : void {
    assert($jobId !== '');

    $id =& drupal_static(self::JOB_ID_VARIABLE_NAME, NULL);
    assert($id === NULL || is_string($id));
    $isTranscription =& drupal_static(self::IS_TRANSCRIPTION_JOB_VARIABLE_NAME, FALSE);
    assert(is_bool($isTranscription));
    $id = $jobId;
    $isTranscription = $isTranscriptionJob;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    return [KernelEvents::TERMINATE => [['handleKernelTerminate']]];
  }

}
