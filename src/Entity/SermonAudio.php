<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\s3fs\StreamWrapper\S3fsStream;
use Drupal\sermon_audio\AwsCredentialsRetriever;
use Drupal\sermon_audio\Exception\ApiCallException;
use Drupal\sermon_audio\Exception\EntityValidationException;
use Drupal\sermon_audio\Exception\InvalidInputAudioFileException;
use Drupal\sermon_audio\Settings;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\ApiHelpers;
use Drupal\sermon_audio\Helper\AudioHelpers;
use Drupal\sermon_audio\Helper\CastHelpers;
use Drupal\sermon_audio\HttpMethod;
use Drupal\sermon_audio\S3ClientFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Ranine\Exception\InvalidOperationException;
use Ranine\Exception\ParseException;
use Ranine\Helper\ParseHelpers;
use Ranine\Helper\ThrowHelpers;
use Ranine\Iteration\ExtendableIterable;

/**
 * An entity representing audio for a sermon.
 *
 * Audio processing of the "unprocessed audio" field can be initiated with the
 * intiateAudioProcessing() method. This method first sets the
 * processing_initiated flag.
 *
 * After a sermon audio entity is intially loaded (this does not occur on
 * subsequent loads within the same request), a "post load" handler checks to
 * see if processing_intiated is set. If the field is set and the processed
 * audio field is not set, a check is made to see if the AWS audio processing
 * job has finished. If it has, the entity's "processed audio" field is updated
 * with a new file entity corresponding to the processed audio, and the entity
 * is saved. The AWS audio processing job check and subsequent processed audio
 * field update can also be forced by calling refreshProcessedAudio().
 *
 * @ContentEntityType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   base_table = "sermon_audio",
 *   data_table = "sermon_audio_field_data",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "langcode" = "langcode",
 *   },
 *   admin_permission = "administer sermon audio",
 *   handlers = {
 *     "access" = "Drupal\sermon_audio\SermonAudioAccessControlHandler",
 *     "storage_schema" = "Drupal\sermon_audio\SermonAudioStorageSchema",
 *   },
 *   constraints = {
 *     "SermonProcessedAudioAndDurationMatchingNullity" = {},
 *     "SermonAudioRequired" = {},
 *   },
 *   translatable = TRUE,
 *   links = {},
 * )
 */
class SermonAudio extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function delete() : void {
    parent::delete();

    // Delete usage information associated with unprocessed audio file entities.
    $fileUsageManager = \Drupal::service('file.usage');
    assert($fileUsageManager instanceof FileUsageInterface);
    $entityTypeId = $this->getEntityTypeId();
    $entityId = (int) $this->id();
    $fileStorage = self::getFileStorage();
    foreach ($this->iterateTranslations() as $translation) {
      $fid = $translation->getUnprocessedAudioId();
      if ($fid !== NULL) {
        $file = $fileStorage->load($fid);
        if ($file !== NULL) {
          assert($file instanceof FileInterface);
          // Remove all usage information for this FID.
          $fileUsageManager->delete($file, 'sermon_audio', $entityTypeId, (string) $entityId, 0);
        }
      }
    }
  }

  /**
   * Tells if a failure was recorded when the cleaning job was last checked.
   */
  public function didCleaningFail() : bool {
    /** @phpstan-ignore-next-line */
    return (bool) $this->cleaning_job_failed->value;
  }

  /**
   * Tells if a failure was recorded when transcription job was last checked.
   */
  public function didTranscriptionFail() : bool {
    /** @phpstan-ignore-next-line */
    return (bool) $this->transcription_job_failed->value;
  }

  /**
   * Gets the audio cleaning job ID, or NULL if there is no active job.
   */
  public function getCleaningJobId() : ?string {
    /** @phpstan-ignore-next-line */
    return CastHelpers::stringyToNullableString($this->cleaning_job_id->value);
  }

  /**
   * Gets the processed audio duration, or NULL if it is not set.
   */
  public function getDuration() : ?float {
    /** @phpstan-ignore-next-line */
    $value = $this->duration->value;
    if ($value === NULL) return NULL;
    else {
      assert(is_scalar($value));
      return (float) $value;
    }
  }

  /**
   * Gets the processed audio file entity.
   *
   * Returns NULL if processed audio file ID is not set. Also returns NULL if
   * $ignoreMissingReference is TRUE and the corresponding file entity does not
   * exist.
   *
   * @throws \RuntimeException
   *   Thrown if $ignoreMissingReference is FALSE, and there is a reference to a
   *   processed audio file but the entity was not found.
   */
  public function getProcessedAudio(bool $ignoreMissingReference = FALSE) : ?FileInterface {
    $targetId = $this->getProcessedAudioId();
    if ($targetId === NULL) return NULL;
    $file = $this->getFileStorage()->load($targetId);
    if ($file === NULL) {
      if ($ignoreMissingReference) return NULL;
      else throw new \RuntimeException('Could not load file entity with ID "' . $targetId . '".');
    }
    else {
      assert($file instanceof FileInterface);
      return $file;
    }
  }

  /**
   * Gets the processed audio file ID, or NULL if not set.
   */
  public function getProcessedAudioId() : ?int {
    return CastHelpers::intyToNullableInt($this->processed_audio->target_id);
  }

  /**
   * Gets the audio transcription job ID, or NULL if there is no active job.
   */
  public function getTranscriptionJobId() : ?string {
    /** @phpstan-ignore-next-line */
    return CastHelpers::stringyToNullableString($this->transcription_job_id->value);
  }

  /**
   * Gets the audio transcription URI, or NULL if not set.
   */
  public function getTranscriptionUri() : ?string {
    /** @phpstan-ignore-next-line */
    return CastHelpers::stringyToNullableString($this->transcription_uri->value);
  }

  /**
   * {@inheritdoc}
   *
   * @return static
   */
  public function getTranslation(mixed $langcode) : static {
    // This method simply provides PHPStan / the IDE with correct type
    // information.
    $translation = parent::getTranslation($langcode);
    assert($translation instanceof static);
    return $translation;
  }

  /**
   * Gets the unprocessed audio file entity.
   *
   * Returns NULL if unprocessed audio file ID is not set. Also returns NULL if
   * $ignoreMissingReference is TRUE and the corresponding file entity does not
   * exist.
   *
   * @throws \RuntimeException
   *   Thrown if $ignoreMissingReference is FALSE, and there is a reference to
   *   an unprocessed audio file but the entity was not found.
   */
  public function getUnprocessedAudio(bool $ignoreMissingReference = FALSE) : ?FileInterface {
    $targetId = $this->getUnprocessedAudioId();
    if ($targetId === NULL) return NULL;
    $file = $this->getFileStorage()->load($targetId);
    if ($file === NULL) {
      if ($ignoreMissingReference) return NULL;
      else throw new \RuntimeException('Could not load file entity with ID "' . $targetId . '".');
    }
    else {
      assert($file instanceof FileInterface);
      return $file;
    }
  }

  /**
   * Gets the unprocessed audio file ID, or NULL if it is not set.
   */
  public function getUnprocessedAudioId() : ?int {
    /** @phpstan-ignore-next-line */
    return CastHelpers::intyToNullableInt($this->unprocessed_audio->target_id);
  }

  /**
   * Tells whether there is a cleaning job ID associated with this entity.
   */
  public function hasCleaningJob() : bool {
    return $this->getCleaningJobId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells if there is a processed audio file ID associated with this entity.
   */
  public function hasProcessedAudio() : bool {
    return $this->getProcessedAudioId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells whether there is a transcription job ID associated with this entity.
   */
  public function hasTranscriptionJob() : bool {
    return $this->getTranscriptionJobId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells if there is an unprocessed audio file ID associated with this entity.
   */
  public function hasUnprocessedAudio() : bool {
    return $this->getUnprocessedAudioId() === NULL ? FALSE : TRUE;
  }

  /**
   * Initiates processing job(s) corresponding to the unprocessed audio file.
   *
   * Initiates audio processing, consisting of audio cleaning as well as (if
   * requested) audio transcription transcription. Once the cleaning and
   * (possibly) transcription job IDs are obtained, the corresponding entity
   * fields are updated and the entity is saved.
   *
   * @param string $sermonName
   *   Sermon name corresponding to audio.
   * @phpstan-param non-empty-string $sermonName
   * @param string $sermonSpeakerFirstNames
   *   First name(s) of sermon speaker corresponding to audio.
   * @param string $sermonSpeakerLastName
   *   Last name of sermon speaker corresponding to audio.
   * @param int $sermonYear
   *   Sermon year corresponding to audio.
   * @phpstan-param positive-int $sermonYear
   * @param string $sermonCongregation
   *   Sermon congregation corresponding to audio.
   * @phpstan-param non-empty-string $sermonCongregation
   * @param string $sermonLanguageCode
   *   Sermon language code corresponding to audio.
   * @phpstan-param non-empty-string $sermonLanguageCode
   * @param bool $transcribe
   *   Whether to transcribe the audio.
   * @param bool $throwOnFailure
   *   TRUE if an exception should be thrown if the processing initiation fails
   *   for certain "expected" (and recoverable; that is, execution of the caller
   *   should continue without worrying about program state corruption) reasons:
   *   that is, because of 1) AWS or HTTP errors, 2) validation issues with this
   *   entity, or 3) missing linked entiti(es).
   * @param null|\Exception $failureException
   *   (output) If $throwOnFailure is FALSE and an "expected" exception occurs
   *   (see above), this parameter is set to the exception that occurred. This
   *   is NULL if no error occurs, or if $throwOnFailure is TRUE.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when attempting to save the current entity.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if one of this module's settings is missing or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "endpoint_timeout"
   *   configuration setting is invalid.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonName, $sermonYear, $sermonCongregation, or
   *   $sermonLanguageCode is empty.
   * @throws \InvalidArgumentException
   *   Thrown if both $sermonSpeakerFirstNames and $sermonSpeakerLastName are
   *   consist only of whitespace.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonYear is less than or equal to zero.
   * @throws \RuntimeException
   *   Thrown if the unprocessed audio file entity could not be loaded.
   * @throws \Drupal\sermon_audio\Exception\ApiCallException
   *   Thrown if an error occurs when making an HTTP request to the audio
   *   processing job submission API, or if the response is invalid.
   */
  public function initiateAudioProcessing(string $sermonName,
    string $sermonSpeakerFirstNames,
    string $sermonSpeakerLastName,
    int $sermonYear,
    string $sermonCongregation,
    string $sermonLanguageCode,
    bool $transcribe = TRUE,
    bool $throwOnFailure = TRUE,
    \Exception &$failureException = NULL) : void {
    ThrowHelpers::throwIfEmptyString($sermonName, 'sermonName');
    ThrowHelpers::throwIfEmptyString($sermonCongregation, 'sermonCongregation');
    ThrowHelpers::throwIfEmptyString($sermonLanguageCode, 'sermonLanguageCode');
    ThrowHelpers::throwIfLessThanOrEqualToZero($sermonYear, 'sermonYear');

    $sermonSpeakerFirstNames = trim($sermonSpeakerFirstNames);
    $sermonSpeakerLastName = trim($sermonSpeakerLastName);
    if ($sermonSpeakerFirstNames === '' && $sermonSpeakerLastName === '') {
      throw new \InvalidArgumentException('Both $sermonSpeakerFirstNames and $sermonSpeakerLastName are empty or are only whitespace.');
    }

    $throwIfDesired = function(\Exception $e, ?callable $alwaysThrowPredicate = NULL) use ($throwOnFailure, &$failureException) {
      if ($throwOnFailure || ($alwaysThrowPredicate !== NULL && $alwaysThrowPredicate($e))) throw $e;
      else $failureException = $e;
    };

    try {
      $unprocessedAudio = $this->getUnprocessedAudio() ?? throw self::getUnprocessedAudioFieldException();
      $inputSubKey = self::getUnprocessedAudioSubKey($unprocessedAudio);
    }
    catch (\Exception $e) {
      $throwIfDesired($e, fn($e) => !($e instanceof EntityValidationException || $e instanceof InvalidInputAudioFileException || $e instanceof \RuntimeException));
      return;
    }

    // Don't actually start any audio processing jobs if we're in "debug mode,"
    // but do set the job IDs to fake values.
    if (Settings::isDebugModeEnabled()) {
      $this->setCleaningJob('abcdef');
      if ($transcribe) $this->setTranscriptionJob('123456');
      return;
    }

    $sermonSpeakerFullName = $sermonSpeakerFirstNames . ' ' . $sermonSpeakerLastName;
    $sermonSpeakerNormalized = self::asciify($sermonSpeakerLastName . ' ' . $sermonSpeakerFirstNames, $sermonLanguageCode);
    $sermonNameNormalized = self::asciify($sermonName, $sermonLanguageCode);

    $jobSubmissionApiEndpoint = Settings::getJobSubmissionApiEndpoint();
    if ($jobSubmissionApiEndpoint === '') {
      throw new ModuleConfigurationException('The "job_submission_api_endpoint" module setting is empty.');
    }
    $jobSubmissionApiRegion = Settings::getJobSubmissionApiRegion();
    if ($jobSubmissionApiRegion === '') {
      throw new ModuleConfigurationException('The "job_submission_api_region" module setting is empty.');
    }
    $processingRequestData = [
      'input-sub-key' => $inputSubKey,
      'sermon-language' => $sermonLanguageCode,
      'transcribe' => $transcribe,
      'sermon-name' => $sermonName,
      'sermon-name-normalized' => $sermonNameNormalized,
      'sermon-speaker' => $sermonSpeakerFullName,
      'sermon-speaker-normalized' => $sermonSpeakerNormalized,
      'sermon-year' => $sermonYear,
      'sermon-congregation' => $sermonCongregation,
    ];
    try {
      $response = ApiHelpers::callApi(\Drupal::httpClient(),
        static::getCredentialsRetriever()->getCredentials(),
        $jobSubmissionApiEndpoint,
        $jobSubmissionApiRegion,
        $processingRequestData,
        [],
        HttpMethod::POST);
    }
    catch (ClientExceptionInterface $e) {
      $throwIfDesired(new ApiCallException('An error occurred when calling the audio processing job submission api.', $e->getCode(), $e));
      return;
    }

    if (!self::isResponseStatusCodeValid($response)) {
      $throwIfDesired(new ApiCallException('The audio processing job submission API returned a faulty status code of ' . $responseStatusCode . '.'));
      return;
    }

    try {
      $responseBody = $response->getBody()->getContents();
    }
    catch (\RuntimeException $e) {
      $throwIfDesired(new ApiCallException('An error occurred when trying to read the response body from the audio processing job submission API.', $e->getCode(), $e));
      return;
    }

    $decodedResponse = json_decode($responseBody, TRUE);
    if (!is_array($decodedResponse)) {
      $throwIfDesired(new ApiCallException('The audio processing job submission API returned an invalid response body.'));
      return;
    }
    if (!isset($decodedResponse['cleaning-job-id'])) {
      $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that did not contain a "cleaning-job-id" property.'));
      return;
    }
    $cleaningJobId = $decodedResponse['cleaning-job-id'];
    if (!is_scalar($cleaningJobId)) {
      $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that contained an invalid "cleaning-job-id" property.'));
      return;
    }
    $cleaningJobId = (string) $cleaningJobId;
    if ($cleaningJobId === '') {
      $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that contained an empty "cleaning-job-id" property.'));
      return;
    }

    if ($transcribe) {
      if (!isset($decodedResponse['transcription-job-id'])) {
        $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that did not contain a "transcription-job-id" property.'));
        return;
      }
      $transcriptionJobId = $decodedResponse['transcription-job-id'];
      if (!is_scalar($transcriptionJobId)) {
        $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that contained an invalid "transcription-job-id" property.'));
        return;
      }
      $transcriptionJobId = (string) $transcriptionJobId;
      if ($transcriptionJobId === '') {
        $throwIfDesired(new ApiCallException('The audio processing job submission API returned a response body that contained an empty "transcription-job-id" property.'));
        return;
      }
    }
    else $transcriptionJobId = NULL;

    $this->setCleaningJob($cleaningJobId);
    if (isset($transcriptionJobId)) $this->setTranscriptionJob($transcriptionJobId);

    $this->save();
  }

  /**
   * Iterates over all translations of this entity.
   *
   * @return \Ranine\Iteration\ExtendableIterable<string|int, static>
   *   Iterable, whose keys are the langcodes, and whose values are the
   *   translated entities.
   */
  public function iterateTranslations() : iterable {
    return ExtendableIterable::from($this->getTranslationLanguages())
      ->map(fn($langcode) => $this->getTranslation($langcode));
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, mixed $update = TRUE) : void {
    parent::postSave($storage, $update);

    // We have to announce file usage for the unprocessed audio field, since
    // this field is a plain entity reference field instead of a file field.
    // This is adapated from @see \Drupal\file\Plugin\Field\FieldType\FileFieldItemList::postSave().
    // Note that unlike in the method referenced above, we don't have to worry
    // about revision stuff, as our entity type is not revisionable.

    // Record by what amount the usage should change for each FID.
    /* @var array<int, int>*/
    $usageChanges = [];
    if ($update) {
      if (!isset($this->original) || !($this->original instanceof SermonAudio)) {
        throw new \RuntimeException('Missing our invalid original entity.');
      }
      $originalEntity = $this->original;

      // Combine the langcodes from both the original and current entity.
      $langcodesToScanAsKeys = ExtendableIterable::from($this->getTranslationLanguages())
        ->append($originalEntity->getTranslationLanguages())
        ->map(fn() => NULL)
        ->toArray();
      foreach ($langcodesToScanAsKeys as $langcode => $n) {
        $langcode = (string) $langcode;
        $originalFid = NULL;
        if ($originalEntity->hasTranslation($langcode)) {
          $originalTranslation = $originalEntity->getTranslation($langcode);
          assert($originalTranslation instanceof SermonAudio);
          $originalFid = $originalTranslation->getUnprocessedAudioId();
        }
        $newFid = NULL;
        if ($this->hasTranslation($langcode)) {
          $newTranslation = $this->getTranslation($langcode);
          assert($newTranslation instanceof SermonAudio);
          $newFid = $newTranslation->getUnprocessedAudioId();
        }

        // Depending on how the original FID and new FID compare, change the
        // usage values.
        if ($originalFid !== $newFid) {
          if ($originalFid !== NULL) {
            if (!array_key_exists($originalFid, $usageChanges)) $usageChanges[$originalFid] = -1;
            else $usageChanges[$originalFid]--;
          }
          if ($newFid !== NULL) {
            if (!array_key_exists($newFid, $usageChanges)) $usageChanges[$newFid] = 1;
            else $usageChanges[$newFid]++;
          }
        }
      }
    }
    else {
      foreach ($this->iterateTranslations() as $translation) {
        $fid = $translation->getUnprocessedAudioId();
        if ($fid !== NULL) {
          if (!array_key_exists($fid, $usageChanges)) $usageChanges[$fid] = 1;
          else $usageChanges[$fid]++;
        }
      }
    }

    $fileUsageManager = \Drupal::service('file.usage');
    assert($fileUsageManager instanceof FileUsageInterface);
    $fileStorage = self::getFileStorage();
    $entityTypeId = $this->getEntityTypeId();
    $entityId = (int) $this->id();
    foreach ($usageChanges as $fid => $change) {
      if ($change === 0) continue;
      $file = $fileStorage->load($fid);
      if ($file === NULL) continue;
      assert($file instanceof FileInterface);

      if ($change > 0) $fileUsageManager->add($file, 'sermon_audio', $entityTypeId, (string) $entityId, $change);
      else $fileUsageManager->delete($file, 'sermon_audio', $entityTypeId, (string) $entityId, -$change);
    }
  }

  /**
   * Handles any audio cleaning result.
   *
   * If the "debug_mode" module setting is active, the processed audio field is
   * set to the unprocessed audio field, and the duration field is set to zero.
   * The processed audio job ID is also unset.
   *
   * Otherwise, if there is a (presumably active) processed audio job attached
   * to this entity, a check is made to see if the job has finished. If so, the
   * processed audio field is updated with the processed audio URI and the
   * duration is updated to the value computed by the job. The job ID is also
   * unset. The processed audio URI and duration, though, are not changed if
   * the new URI is the same as that currently associated with the processed
   * audio field. If the job has not finished, but has failed, the job ID is
   * unset and the job failure flag is set.
   *
   * Note that this method performs its function even if the current processed
   * audio field value is non-NULL.
   *
   * This entity is not saved in this method -- that is up to the caller.
   *
   * @return bool
   *   TRUE if this entity may have been changed, else FALSE.
   *
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a HEAD request
   *   for a new processed audio file.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if debug mode is enabled and the unprocessed audio file field is
   *   not set.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save a new file entity.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path",
   *   "audio_s3_aws_region", "cleaning_job_results_endpoint", or
   *   "cleaning_job_results_endpoint_aws_region" configuration setting is empty
   *   or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "endpoint_timeout"
   *   configuration setting is invalid.
   * @throws \Ranine\Exception\ParseException
   *   Thrown if the file size of the processsed audio file could not be parsed.
   * @throws \RuntimeException
   *   Thrown if the new processed audio URI references an S3 location that was
   *   reported nonexistent when a HEAD query was made for it, or if the HEAD
   *   query response is invalid in some way.
   * @throws \RuntimeException
   *   Thrown if the "s3fs" module was enabled, but a class from that module is
   *   missing or a service from that module is missing or of the incorrect
   *   type.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if there is no cleaning job ID associated with this entity.
   * @throws \Drupal\sermon_audio\Exception\ApiCallException
   *   Thrown if an error occurs when making a call to the job results API.
   */
  public function refreshProcessedAudio() : bool {
    if (!$this->hasCleaningJob()) {
      throw new InvalidOperationException('There is no cleaning job ID associated with this entity.');
    }

    return $this->prepareToRefreshProcessedAudio()();
  }

  /**
   * Handles any audio transcription result.
   *
   * If the "debug_mode" module setting is active, the transcription sub-key is
   * set to a generic value and the transcription job is cleared.
   *
   * Otherwise, if there is a (presumably active) audio transcription job
   * attached to this entity, a check is made to see if the job has finished. If
   * so, the transcription sub-key field is updated with the new S3 sub-key and
   * the job ID is unset. Note that the "new transcription" event is not fired
   * by this method. Also note that none of this happens (except for the job ID
   * being unset) if the new URI is the same as this entity's current URI. If
   * the job has not finished, but has failed, the job ID is unset and the job
   * failure flag is set.
   *
   * This method performs its function even if the current
   * transcription URI field value is non-NULL.
   *
   * This entity is not saved in this method -- that is up to the caller.
   *
   * @return bool
   *   TRUE if this entity may have been changed, else FALSE.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path",
   *   "transcription_job_results_endpoint", or
   *   "transcription_job_results_endpoint_aws_region" configuration setting is
   *   empty or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "endpoint_timeout"
   *   configuration setting is invalid.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if there is no transcription job ID associated with this entity.
   * @throws \Drupal\sermon_audio\Exception\ApiCallException
   *   Thrown if an error occurs when making a call to the job results API.
   */
  public function refreshTranscription() : bool {
    if (!$this->hasTranscriptionJob()) {
      throw new InvalidOperationException('There is no transcription job ID associated with this entity.');
    }

    return $this->prepareToRefreshTranscription()();
  }

  /**
   * Unsets the cleaning job ID and records a cleaning job failure.
   */
  private function failCleaningJob() : void {
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_failed = TRUE;
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_id = NULL;
  }

  /**
   * Unsets the transcription job ID and records a transcription job failure.
   */
  private function failTranscriptionJob() : void {
    /** @phpstan-ignore-next-line */
    $this->transcription_job_failed = TRUE;
    /** @phpstan-ignore-next-line */
    $this->transcription_job_id = NULL;
  }

  /**
   * Returns a setter for any audio cleaning result.
   *
   * The setter sets the fields of this entity as described in
   * self::refreshProcessedAudio().
   *
   * NOTE: See self::refreshProcessedAudio() for the exceptions that are thrown
   * (everything except the \Ranine\Exception\InvalidOperationException
   * exception).
   *
   * @return callable() : bool
   *   Setter returning TRUE if the entity may have been changed; else it
   *   returns FALSE.
   */
  private function prepareToRefreshProcessedAudio() : callable {
    assert($this->hasCleaningJob());

    if (Settings::isDebugModeEnabled()) {
      $unprocessedAudioId = $this->getUnprocessedAudioId() ?? throw self::getUnprocessedAudioFieldException();
      return function () use($unprocessedAudioId) : bool {
        $this->setProcessedAudioTargetId($unprocessedAudioId);
        $this->unsetCleaningJob();
        $this->setDuration(0);
        return TRUE;
      };
    }

    $cleaningJobResultsApiEndpoint = Settings::getCleaningJobResultsApiEndpoint();
    if ($cleaningJobResultsApiEndpoint === '') {
      throw new ModuleConfigurationException('The "cleaning_job_results_endpoint" module setting is empty.');
    }
    $cleaningJobResultsApiRegion = Settings::getCleaningJobResultsApiRegion();
    if ($cleaningJobResultsApiRegion === '') {
      throw new ModuleConfigurationException('The "cleaning_job_results_endpoint_aws_region" module setting is empty.');
    }
    try {
      $response = ApiHelpers::callApi(\Drupal::httpClient(),
        static::getCredentialsRetriever()->getCredentials(),
        $cleaningJobResultsApiEndpoint,
        $cleaningJobResultsApiRegion,
        [],
        ['id' => $this->getCleaningJobId()],
        HttpMethod::GET);
    }
    catch (ClientExceptionInterface $e) {
      throw new ApiCallException('An error occurred when calling the audio cleaning job results api.', $e->getCode(), $e);
    }

    if (!self::isResponseStatusCodeValid($response)) {
      throw new ApiCallException('The audio cleaning job results API returned a faulty status code of ' . $responseStatusCode . '.');
    }
    $jobResults = self::decodeJsonResponseBody($response);
    if ($jobResults === NULL) {
      throw new ApiCallException('Could not read or decode audio cleaning job results API response body.');
    }
    if (!isset($jobResults['status'])) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that did not contain a valid "status" property.');
    }
    $jobStatus = 0;
    if (!ParseHelpers::tryParseInt($jobResults['status'], $jobStatus)) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that contained a non-integral "status" property.');
    }
    if ($jobStatus === -1) {
      return function() {
        $this->failCleaningJob();
        return TRUE;
      };
    }
    elseif ($jobStatus !== 2) {
      // Job is presumably not finished yet.
      return fn() => FALSE;
    }

    if (!isset($jobResults['output-sub-key'])) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that did not contain a valid "output-sub-key" property.');
    }
    $outputSubKey = CastHelpers::stringyToString($jobResults['output-sub-key']);
    if ($outputSubKey === '') {
      throw new ApiCallException('The audio cleaning job results API returned a response body that contained an empty "output-sub-key" property.');
    }
    if (!isset($jobResults['duration'])) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that did not contain a valid "duration" property.');
    }
    $duration = $jobResults['duration'];
    if (!is_numeric($duration)) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that contained a non-numeric "duration" property.');
    }
    $duration = (float) $duration;
    if (!is_finite($duration) || $duration < 0) {
      throw new ApiCallException('The audio cleaning job results API returned a response body that contained non-finite or negative "duration" property.');
    }

    $processedAudioUri = Settings::getProcessedAudioUriPrefix() . $outputSubKey;

    // Before creating a new file entity, check to see if the current processed
    // audio entity already references the correct URI.
    $processedAudio = $this->getProcessedAudio();
    if ($processedAudio !== NULL && $processedAudio->getFileUri() === $processedAudioUri) {
      return FALSE;
    }

    // If the s3fs module is enabled, we will go ahead and cache metadata for
    // the processed audio file. This will also allow the file size to be
    // automatically set when the processed audio file entity is created.
    // Otherwise, we'll have to grab the file size separately.
    if (\Drupal::moduleHandler()->moduleExists('s3fs')) {
      $s3fsStreamWrapper = \Drupal::service('stream_wrapper.s3fs');
      if (!class_exists('Drupal\\s3fs\\StreamWrapper\\S3fsStream')) {
        throw new \RuntimeException('The "s3fs" module was enabled, but the \\Drupal\\s3fs\\StreamWrapper\\S3fsStream class does not exist.');
      }
      if(!($s3fsStreamWrapper instanceof S3fsStream)) {
        throw new \RuntimeException('The "s3fs" module was enabled, but the "stream_wrapper.s3fs" is not of the expected type.');
      }
      $s3fsStreamWrapper->writeUriToCache($processedAudioUri);
    }
    else {
      // Get the size of the new processed audio file. We do this by making a
      // HEAD request for the file.
      $s3Client = self::getS3Client();
      $result = $s3Client->headObject(['Bucket' => self::getAudioBucket(), 'Key' => self::getS3ProcessedAudioKeyPrefix() . $outputSubKey]);
      if (!isset($result['ContentLength'])) {
        throw new \RuntimeException('Could not retrieve file size for processed audio file.');
      }
      $fileSize = ParseHelpers::parseInt($result['ContentLength']);
      if ($fileSize < 0) {
        throw new \RuntimeException('Invalid file size for processed audio file.');
      }
    }

    // Create the new processed audio file entity, setting its owner to the
    // owner of the unprocessed audio file.
    $owner = $unprocessedAudio->getOwnerId();
    $newProcessedAudioFieldInitValues = [
      'uri' => $processedAudioUri,
      'uid' => $owner,
      'filename' => $outputDisplayFilename,
      'filemime' => 'audio/mp4',
      'status' => TRUE,
    ];
    if (isset($fileSize)) {
      // If the file size was captured above, set it. Otherwise, it should be
      // automatically set when the entity creation/save process is executed
      // below.
      $newProcessedAudioFieldInitValues['filesize'] = $fileSize;
    }
    $newProcessedAudio = self::getFileStorage()
      ->create($newProcessedAudioFieldInitValues)
      ->enforceIsNew();
    $newProcessedAudio->save();
    $newProcessedAudioId = (int) $newProcessedAudio->id();

    return function() use ($newProcessedAudioId, $duration) : bool {
      $this->setProcessedAudioTargetId($newProcessedAudioId);
      $this->setDuration($duration);
      $this->unsetCleaningJob();
      return TRUE;
    };
  }

  /**
   * Returns a setter for any audio transcription result.
   *
   * The setter sets the fields of this entity as described in
   * self::refreshProcessedTranscription().
   *
   * NOTE: See self::refreshProcessedTranscription() for the exceptions that are
   * thrown (everything except the \Ranine\Exception\InvalidOperationException
   * exception).
   *
   * @return callable() : bool
   *   Setter returning TRUE if the entity may have been changed; else it
   *   returns FALSE.
   */
  private function prepareToRefreshTranscription() : callable {
    assert($this->hasTranscriptionJob());

    if (Settings::isDebugModeEnabled()) {
      return function () : bool {
        $this->setTranscriptionUri('https://example.com/transcription.xml');
        $this->unsetTranscriptionJob();
        return TRUE;
      };
    }

    $transcriptionJobResultsApiEndpoint = Settings::getTranscriptionJobResultsApiEndpoint();
    if ($transcriptionJobResultsApiEndpoint === '') {
      throw new ModuleConfigurationException('The "transcription_job_results_endpoint" module setting is empty.');
    }
    $transcriptionJobResultsApiRegion = Settings::getTranscriptionJobResultsApiRegion();
    if ($transcriptionJobResultsApiRegion === '') {
      throw new ModuleConfigurationException('The "transcription_job_results_endpoint_aws_region" module setting is empty.');
    }
    try {
      $response = ApiHelpers::callApi(\Drupal::httpClient(),
        static::getCredentialsRetriever()->getCredentials(),
        $transcriptionJobResultsApiEndpoint,
        $transcriptionJobResultsApiRegion,
        [],
        ['id' => $this->getTranscriptionJobId()],
        HttpMethod::GET);
    }
    catch (ClientExceptionInterface $e) {
      throw new ApiCallException('An error occurred when calling the audio transcription job results api.', $e->getCode(), $e);
    }

    if (!self::isResponseStatusCodeValid($response)) {
      throw new ApiCallException('The audio transcription job results API returned a faulty status code of ' . $responseStatusCode . '.');
    }
    $jobResults = self::decodeJsonResponseBody($response);
    if ($jobResults === NULL) {
      throw new ApiCallException('Could not read or decode audio transcription job results API response body.');
    }
    if (!isset($jobResults['status'])) {
      throw new ApiCallException('The audio transcription job results API returned a response body that did not contain a valid "status" property.');
    }
    $jobStatus = 0;
    if (!ParseHelpers::tryParseInt($jobResults['status'], $jobStatus)) {
      throw new ApiCallException('The audio transcription job results API returned a response body that contained a non-integral "status" property.');
    }
    if ($jobStatus === -1) {
      return function() {
        $this->failTranscriptionJob();
        return TRUE;
      };
    }
    elseif ($jobStatus !== 2) {
      // Job is presumably not finished yet.
      return fn() => FALSE;
    }

    if (!isset($jobResults['output-sub-key'])) {
      throw new ApiCallException('The audio transcription job results API returned a response body that did not contain a valid "output-sub-key" property.');
    }
    $outputSubKey = CastHelpers::stringyToString($jobResults['output-sub-key']);
    if ($outputSubKey === '') {
      throw new ApiCallException('The audio transcription job results API returned a response body that contained an empty "output-sub-key" property.');
    }

    return function () use($outputSubKey) : bool {
      $this->setTranscriptionSubKey($outputSubKey);
      $this->unsetTranscriptionJob();
      return TRUE;
    };
  }

  /**
   * Sets the cleaning job ID to the given value.
   *
   * @phpstan-param non-empty-string $jobId
   */
  private function setCleaningJob(string $jobId) : void {
    assert($jobId !== '');
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_failed = FALSE;
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_id = $jobId;
  }

  /**
   * Sets the transcription job ID to the given value.
   *
   * @phpstan-param non-empty-string $jobId
   */
  private function setTranscriptionJob(string $jobId) : void {
    assert($jobId !== '');
    /** @phpstan-ignore-next-line */
    $this->transcription_job_failed = FALSE;
    /** @phpstan-ignore-next-line */
    $this->transcription_job_id = $jobId;
  }

  /**
   * Sets the cleaning job ID to NULL.
   */
  private function unsetCleaningJob() : void {
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_id = NULL;
  }

  /**
   * Sets the transcription job ID to NULL.
   */
  private function unsetTranscriptionJob() : void {
    /** @phpstan-ignore-next-line */
    $this->transcription_job_id = NULL;
  }

  /**
   * Sets the audio duration to the given value.
   */
  private function setDuration(float $value) : void {
    assert($value >= 0);
    /** @phpstan-ignore-next-line */
    $this->duration = $value;
  }

  /**
   * Sets the processed audio target ID to the given value.
   *
   * @phpstan-param int<0, max> $id
   */
  private function setProcessedAudioTargetId(int $id) : void {
    assert($id >= 0);
    /** @phpstan-ignore-next-line */
    $this->processed_audio = $id;
  }

  /**
   * Sets the transcription sub-key to the given value.
   * 
   * @phpstan-param non-empty-string $subKey
   */
  private function setTranscriptionSubKey(string $subKey) : void {
    assert($subKey !== '');
    /** @phpstan-ignore-next-line */
    $this->transcription_sub_key = $subKey;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) : array {
    $fields = parent::baseFieldDefinitions($entity_type);
    
    // @todo Do we need anything in hook_update_N() to add new fields / remove
    // old ones?

    $fields['transcription_job_failed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Transcription Job Failed?'))
      ->setDescription(new TranslatableMarkup('Tells whether there is a confirmed failure of the audio transcription job.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setDefaultValue(FALSE);
    $fields['transcription_sub_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Transcription Sub-Key'))
      ->setDescription(new TranslatableMarkup('S3 sub-key of transcription XML file.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE);
    $fields['transcription_job_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Transcription Job ID'))
      ->setDescription(new TranslatableMarkup('Audio transcription job ID. NULL if there is known to be no active transcription job.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE);
    $fields['cleaning_job_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Cleaning Job ID'))
      ->setDescription(new TranslatableMarkup('Audio cleaning job ID. NULL if there is known to be no active cleaning job.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE);
    $fields['cleaning_job_failed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Cleaning Job Failed?'))
      ->setDescription(new TranslatableMarkup('Tells whether there is a confirmed failure of the audio cleaning job.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setDefaultValue(FALSE);
    $fields['duration'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Duration'))
      ->setDescription(new TranslatableMarkup('Duration of processed sermon audio.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 0);
    $fields['processed_audio'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Processed Audio'))
      ->setDescription(new TranslatableMarkup('Processed audio file.'))
      ->setCardinality(1)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setSetting('file_extensions', 'mp4');
    // We use an entity reference instead of a file field because 1) we do not
    // need the extra features provided by the file field type, and 2) we would
    // rather not have restrictions on the possible file extensions (these can
    // instead be imposed on sermon audio fields), and the file field does not
    // permit one to allow all extensions. However, this way of doing it does
    // have its costs -- for instance, we have to implement file usage updates
    // manually (see delete() and postSave()).
    $fields['unprocessed_audio'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Unprocessed Audio'))
      ->setDescription(new TranslatableMarkup('Unprocessed audio file.'))
      ->setCardinality(1)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setSetting('target_type', 'file');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) : void {
    /** @var null[] */
    static $finishedEntityIds = [];
    foreach ($entities as $entity) {
      if (!($entity instanceof SermonAudio)) {
        throw new \RuntimeException('Invalid entity type in $entities.');
      }

      $entityId = $entity->id();
      assert($entityId !== NULL);

      // Don't do anything if postLoad() has already been run for this entity.
      // This avoids various problems with the static entity cache being cleared
      // and refreshProcessedAudio() thus being called multiple times on the
      // same entity in the same request cycle (such as when it is called again
      // when the entity is saved).
      if (array_key_exists($entityId, $finishedEntityIds)) continue;

      $requiresSave = FALSE;

      // We'll have to refresh for all translations, as postLoad() is only
      // called once for all translations.
      foreach ($entity->iterateTranslations() as $translation) {
        if (!AudioHelpers::isProcessedAudioRefreshable($translation)) continue;

        try {
          $translationUpdate = $translation->prepareToRefreshProcessedAudio();
        }
        catch (\Exception $e) {
          if ($e instanceof DynamoDbException
            || $e instanceof S3Exception
            || $e instanceof EntityStorageException
            || $e instanceof InvalidInputAudioFileException
            || $e instanceof ModuleConfigurationException
            || $e instanceof ApiCallException
            || $e instanceof ParseException) {
            // For "expected exceptions," we don't want to blow up in
            // postLoad(). Instead, we simply log the exception, and continue to
            // the next translation.
            watchdog_exception('sermon_audio', $e, NULL, [], RfcLogLevel::WARNING);
            continue;
          }
          else throw $e;
        }
        if ($translationUpdate()) $requiresSave = TRUE;
      }

      if ($requiresSave) {
        // We add the entity ID to the $finishedEntityIds set before saving.
        // This is because the save process will invoke postLoad() again (when
        // loading the unchanged entity).
        $finishedEntityIds[$entityId] = NULL;
        $entity->save();
      }
      else {
        $finishedEntityIds[$entityId] = NULL;
      }
    }
  }

  /**
   * "Transliterates" the given (Unicode) text to ASCII.
   *
   * @param string $text
   *   Text to transliterate.
   * @param string $langcode
   *   Language code of $text language.
   * @phpstan-param non-empty-string $langcode
   *
   * @return string
   *   Transliterated text. "\\" is used for unknown characters.
   */
  private static function asciify(string $text, string $langcode) : string {
    // Try to "transliterate" the segment to get an approximate ASCII
    // representation. It won't be perfect, but that's okay. Use "\" for unknown
    // characters to ensure these characters don't get merged with whitespace,
    // etc. in the processing below (and are instead later replaced with "-").
    return \Drupal::transliteration()->transliterate($segment, $langcode, '\\');
  }

  /**
   * Gets the S3 bucket for processed and unprocessed audio.
   *
   * @return string
   *   Bucket name.
   * @phpstan-return non-empty-string
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the bucket name module setting is empty.
   */
  private static function getAudioBucket() : string {
    $bucketName = Settings::getAudioBucketName();
    if ($bucketName === '') {
      throw new ModuleConfigurationException('The audio bucket name module setting is empty.');
    }
    return $bucketName;
  }

  /**
   * Gets the credentials retriever from the service container.
   */
  private static function getCredentialsRetriever() : AwsCredentialsRetriever {
    $credentialsRetriever = \Drupal::service('sermon_audio.credentials_retriever');
    assert($credentialsRetriever instanceof AwsCredentialsRetriever);
    return $credentialsRetriever;
  }

  /**
   * Attempts to decode the $response body as JSON.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   Response whose body we should decode.
   *
   * @return array|null
   *   The response body, if it could be decoded, or NULL if not.
   */
  private static function decodeJsonResponseBody(ResponseInterface $response) : ?array {
    try {
      $responseBody = $response->getBody()->getContents();
    }
    catch (\RuntimeException $e) {
      return NULL;
    }
    $decodedResponse = json_decode($responseBody, TRUE);
    if (!is_array($decodedResponse)) {
      return NULL;
    }

    return $decodedResponse;
  }

  /**
   * Gets the file storage.
   */
  private static function getFileStorage() : FileStorageInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('file');
    assert($storage instanceof FileStorageInterface);
    return $storage;
  }

  /**
   * Gets an S3 client.
   */
  private static function getS3Client() : S3Client {
    $dynamoDbClientFactory = \Drupal::service('sermon_audio.s3_client_factory');
    assert($dynamoDbClientFactory instanceof S3ClientFactory);
    return $dynamoDbClientFactory->getClient();
  }

  /**
   * Gets the S3 key prefix for processed audio.
   *
   * @return string
   *   Non-empty key prefix.
   * @phpstan-return non-empty-string
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the processed audio key prefix module setting is empty.
   */
  private static function getS3ProcessedAudioKeyPrefix() : string {
    $prefix = Settings::getProcessedAudioKeyPrefix();
    if ($prefix === '') {
      throw new ModuleConfigurationException('The processed audio key prefix module setting is empty.');
    }
    return $prefix;
  }

  /**
   * Gets a new exception indicating the unprocessed audio file doesn't exist.
   */
  private static function getUnprocessedAudioFieldException() : EntityValidationException {
    return new EntityValidationException('The unprocessed audio field has no value.');
  }

  /**
   * Gets the input sub-key from the URI of the given file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   Unprocessed audio file entity.
   *
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   */
  private static function getUnprocessedAudioSubKey(FileInterface $file) : string {
    $uri = $file->getFileUri();

    $prefix = Settings::getUnprocessedAudioUriPrefix();
    if (!str_starts_with($uri, $prefix)) {
      throw new InvalidInputAudioFileException('Input audio file URI prefix was incorrect.');
    }

    $inputSubKey = substr($uri, strlen($prefix));
    if (!is_string($inputSubKey) || $inputSubKey === '') {
      throw new InvalidInputAudioFileException('Input audio file URI has an empty or invalid sub-key.');
    }

    return $inputSubKey;
  }

  /**
   * Tells whether the status code for the given API call response is valid.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   Response.
   *
   * @return bool
   *   TRUE if the response status code is in the [200..299] range; else FALSE.
   */
  private static function isResponseStatusCodeValid(ResponseInterface $response) : bool {
    assert($apiName !== '');
    $responseStatusCode = (int) $response->getStatusCode();
    return ($responseStatusCode >= 200 && $responseStatusCode < 300) ? TRUE : FALSE;
  }

}
