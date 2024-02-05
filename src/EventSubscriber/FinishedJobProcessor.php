<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\EventSubscriber;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sermon_audio\Helper\RefreshHelpers;
use Ranine\Helper\StringHelpers;
use Ranine\Helper\ThrowHelpers;
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

  /**
   * Storage for sermon audio entities.
   */
  private EntityStorageInterface $sermonAudioStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->sermonAudioStorage = $entityTypeManager->getStorage('sermon_audio');
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
   * Handles announced finished job after the response is sent.
   *
   * For exception information,
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
      $entities = $this->sermonAudioStorage->loadByProperties(['transcription_job_id' => $jobId]);
      foreach ($entities as $entity) {
        RefreshHelpers::refreshTranscriptionAllTranslations($entity);
      }
    }
    else {
      $entities = $this->sermonAudioStorage->loadByProperties(['cleaning_job_id' => $jobId]);
      foreach ($entities as $entity) {
        RefreshHelpers::refreshProcessedAudioAllTranslations($entity);
      }
    }
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

    $id = &drupal_static(self::JOB_ID_VARIABLE_NAME, NULL);
    assert($id === NULL || is_string($id));
    $isTranscription = &drupal_static(self::IS_TRANSCRIPTION_JOB_VARIABLE_NAME, FALSE);
    assert(is_bool($isTranscription));
    $id = $jobId;
    $isTranscription = $isTranscriptionJob;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      KernelEvents::TERMINATE => [['handleKernelTerminate']],
    ];
  }

}