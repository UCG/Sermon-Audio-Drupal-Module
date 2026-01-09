<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\s3fs\StreamWrapper\S3fsStream;
use Drupal\sermon_audio\Exception\ApiCallException;
use Drupal\sermon_audio\Exception\InvalidSermonAudioFileException;
use Drupal\sermon_audio\Settings;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\AwsApiInvoker;
use Drupal\sermon_audio\HttpMethod;
use Drupal\sermon_audio\S3ClientFactory;
use Drupal\sermon_audio\SermonAudioAccessControlHandler;
use Drupal\sermon_audio\SermonAudioStorageSchema;
use Drupal\sermon_audio\SermonAudioViewsData;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use Ranine\Exception\InvalidOperationException;
use Ranine\Exception\ParseException;
use Ranine\Helper\CastHelpers;
use Ranine\Helper\ParseHelpers;
use Ranine\Helper\ThrowHelpers;
use Ranine\Iteration\ExtendableIterable;

/**
 * An entity representing audio for a sermon.
 */
#[ContentEntityType(id: 'sermon_audio',
  label: new TranslatableMarkup('Sermon Audio'),
  base_table: 'sermon_audio',
  data_table: 'sermon_audio_field_data',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  admin_permission: 'administer sermon audio',
  handlers: [
    'access' => SermonAudioAccessControlHandler::class,
    'storage_schema' => SermonAudioStorageSchema::class,
    'views_data' => SermonAudioViewsData::class,
  ],
  constraints: [
    'SermonProcessedAudioAndDurationMatchingNullity' => [],
    'SermonAudioRequired' => [],
  ],
  translatable: TRUE,
)]
class SermonAudio extends ContentEntityBase {

  /**
   * Keys are sermon audio entity IDs where postLoad() is disabled.
   *
   * @var array<int, true>
   */
  private static array $idsWherePostLoadIsDisabled = [];

  /**
   * Removes this entity's transcription job info, results and associated flags.
   */
  public function clearTranscriptionInformation() : void {
    /** @phpstan-ignore-next-line */
    $this->transcription_job_failed = FALSE;
    /** @phpstan-ignore-next-line */
    $this->transcription_sub_key = NULL;
    $this->unsetTranscriptionJob();
  }

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
    $file = self::getFileStorage()->load($targetId);
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
    /** @phpstan-ignore-next-line */
    return CastHelpers::intyToNullableInt($this->processed_audio->target_id);
  }

  /**
   * Gets clone of this entity with all job/transcription-related fields unset.
   *
   * @return self
   *   New entity, which will be inserted when saved.
   */
  public function getPurifiedClone() : self {
    $clone = $this->createDuplicate();
    $clone->clearTranscriptionInformation();
    $clone->unsetCleaningJob();
    /** @phpstan-ignore-next-line */
    $clone->cleaning_job_failed = FALSE;

    return $clone;
  }

  /**
   * Gets the audio transcription job ID, or NULL if there is no active job.
   */
  public function getTranscriptionJobId() : ?string {
    /** @phpstan-ignore-next-line */
    return CastHelpers::stringyToNullableString($this->transcription_job_id->value);
  }

  /**
   * Gets the audio transcription sub-key, or NULL if not set.
   */
  public function getTranscriptionSubKey() : ?string {
    /** @phpstan-ignore-next-line */
    return CastHelpers::stringyToNullableString($this->transcription_sub_key->value);
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
   *
   * @phpstan-assert-if-true !null $this->getCleaningJobId()
   */
  public function hasCleaningJob() : bool {
    return $this->getCleaningJobId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells if there is a processed audio file ID associated with this entity.
   *
   * @phpstan-assert-if-true !null $this->getProcessedAudioId()
   */
  public function hasProcessedAudio() : bool {
    return $this->getProcessedAudioId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells whether there is a transcription job ID associated with this entity.
   *
   * @phpstan-assert-if-true !null $this->getTranscriptionJobId()
   */
  public function hasTranscriptionJob() : bool {
    return $this->getTranscriptionJobId() === NULL ? FALSE : TRUE;
  }

  /**
   * Tells if there is an unprocessed audio file ID associated with this entity.
   *
   * @phpstan-assert-if-true !null $this->getUnprocessedAudioId()
   */
  public function hasUnprocessedAudio() : bool {
    return $this->getUnprocessedAudioId() === NULL ? FALSE : TRUE;
  }

  /**
   * Initiates processing for processed or unprocessed audio.
   *
   * Initiates audio processing, consisting of audio cleaning as well as (if
   * requested) audio transcription. Once the cleaning and (possibly)
   * transcription job IDs are obtained, the corresponding entity fields are
   * updated and the entity is saved.
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
   * @param int $sermonMonth
   *   Sermon month corresponding to audio.
   * @phpstan-param int<1, 12> $sermonMonth
   * @param int $sermonDay
   *   Sermon day corresponding to audio.
   * @phpstan-param int<1, 31> $sermonDay
   * @param string $sermonCongregation
   *   Sermon congregation corresponding to audio.
   * @phpstan-param non-empty-string $sermonCongregation
   * @param string $sermonLanguageCode
   *   2-letter ISO sermon language code corresponding to audio language.
   * @phpstan-param non-empty-string&lowercase-string $sermonLanguageCode
   * @param bool $transcribe
   *   Whether to transcribe the audio.
   * @param bool $getNewProcessedAudio
   *   Whether new (user-facing) processed audio should be generated for this
   *   entity.
   * @param \Drupal\sermon_audio\Entity\AudioProcessingSource $source
   *   From where the source audio should come. If the source URI does not
   *   start with the expected prefix, the processor will be sent the external
   *   URL corresponding to the source file entity. If that URL is not
   *   publically available, the audio processing will later fail.
   * @param bool $throwOnFailure
   *   TRUE if an exception should be thrown if the processing initiation fails
   *   for certain "expected" (and recoverable; that is, execution of the caller
   *   can continue without worrying about program state corruption) reasons:
   *   that is, because of 1) AWS or HTTP errors, 2) validation issues with this
   *   entity, or 3) missing linked entiti(es).
   * @param ?\Exception $failureException
   *   (output) If $throwOnFailure is FALSE and an "expected" exception occurs
   *   (see above), this parameter is set to the exception that occurred. This
   *   is NULL if no error occurs, or if $throwOnFailure is TRUE.
   *
   * @throws \Drupal\Core\File\Exception\InvalidStreamWrapperException
   *   Thrown if 1) the URI for the audio source file does not have the expected
   *   prefix, and when 2) external URL generation for the source URI was
   *   therefore attempted, but 3) the generation failed because the stream
   *   wrapper for the URI was invalid.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when attempting to save the current entity.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if the source audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidSermonAudioFileException
   *   Thrown if the source audio file URI is empty or otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if one of this module's settings is missing or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "endpoint_timeout"
   *   configuration setting is invalid.
   * @throws \InvalidArgumentException
   *   Thrown if both $transcribe and $getNewProcessedAudio are FALSE.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonName or $sermonCongregation is empty.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonLanguageCode is not of length equal to 2.
   * @throws \InvalidArgumentException
   *   Thrown if both $sermonSpeakerFirstNames and $sermonSpeakerLastName are
   *   consist only of whitespace.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonYear is less than or equal to zero, if $sermonMonth is
   *   not between 1 and 12 (inclusive), or if $sermonDay is not between 1 and
   *   31 (inclusive).
   * @throws \RuntimeException
   *   Thrown if the source audio file entity could not be loaded.
   * @throws \Drupal\sermon_audio\Exception\ApiCallException
   *   Thrown if an error occurs when making an HTTP request to the audio
   *   processing job submission API, or if the response is invalid.
   */
  public function initiateAudioProcessing(string $sermonName,
    string $sermonSpeakerFirstNames,
    string $sermonSpeakerLastName,
    int $sermonYear,
    int $sermonMonth,
    int $sermonDay,
    string $sermonCongregation,
    string $sermonLanguageCode,
    bool $transcribe = TRUE,
    bool $getNewProcessedAudio = TRUE,
    AudioProcessingSource $source = AudioProcessingSource::UNPROCESSED,
    bool $throwOnFailure = TRUE,
    ?\Exception &$failureException = NULL) : void {

    ThrowHelpers::throwIfEmptyString($sermonName, 'sermonName');
    ThrowHelpers::throwIfEmptyString($sermonCongregation, 'sermonCongregation');
    ThrowHelpers::throwIfLessThanOrEqualToZero($sermonYear, 'sermonYear');
    self::throwIfInvalidSermonLanguageCode($sermonLanguageCode, 'sermonLanguageCode');
    self::throwIfInvalidSermonMonth($sermonMonth, 'sermonMonth');
    self::throwIfInvalidSermonDay($sermonDay, 'sermonDay');
    if (!$transcribe && !$getNewProcessedAudio) {
      throw new \InvalidArgumentException('At least one of $transcribe and $getNewProcessedAudio must be TRUE.');
    }

    $sermonSpeakerFullName = '';
    $sermonSpeakerNormalized = '';
    self::prepareSermonSpeakerNamesFromArguments($sermonSpeakerFirstNames,
      $sermonSpeakerLastName,
      'sermonSpeakerFirstNames',
      'sermonSpeakerLastName',
      $sermonLanguageCode,
      $sermonSpeakerFullName,
      $sermonSpeakerNormalized);

    $sermonNameNormalized = self::asciify($sermonName, $sermonLanguageCode);

    $throwIfDesired = function(\Exception $e) use ($throwOnFailure, &$failureException) {
      if ($throwOnFailure) throw $e;
      else $failureException = $e;
    };

    if ($source === AudioProcessingSource::PROCESSED) {
      $sourceAudio = $this->getProcessedAudio();
    }
    elseif ($source === AudioProcessingSource::UNPROCESSED || $this->hasUnprocessedAudio()) {
      $source = AudioProcessingSource::UNPROCESSED;
      $sourceAudio = $this->getUnprocessedAudio();
    }
    elseif ($this->hasProcessedAudio()) {
      $source = AudioProcessingSource::PROCESSED;
      $sourceAudio = $this->getProcessedAudio();
    }
    else {
      $throwIfDesired(new InvalidOperationException('No audio source could be found whose field was set.'));
      return;
    }
    if (!$sourceAudio) {
      $throwIfDesired(new InvalidOperationException('The source audio field was not set.'));
      return;
    }

    $uri = $sourceAudio->getFileUri() ?? '';
    if ($uri === '') {
      throw new InvalidSermonAudioFileException('Input audio file URI is empty.');
    }

    $expectedPrefix = ($source === AudioProcessingSource::PROCESSED) ? Settings::getProcessedAudioUriPrefix() : Settings::getUnprocessedAudioUriPrefix();
    
    if (str_starts_with($uri, $expectedPrefix)) {
      $sourceAudioApiParameterValue = substr($uri, strlen($expectedPrefix));
      if ($sourceAudioApiParameterValue === '') {
        throw new InvalidSermonAudioFileException('Input audio file URI has an empty or invalid sub-key.');
      }

      $sourceAudioApiParameterName = ($source === AudioProcessingSource::PROCESSED) ? 'processed-sub-key' : 'input-sub-key';
    }
    else {
      $urlGenerator = \Drupal::service('file_url_generator');
      assert($urlGenerator instanceof FileUrlGeneratorInterface);
      try {
        $sourceAudioApiParameterValue = $urlGenerator->generateAbsoluteString($uri);
      }
      catch (InvalidStreamWrapperException $e) {
        $throwIfDesired($e);
        return;
      }
      if ($sourceAudioApiParameterValue == '') {
        throw new InvalidSermonAudioFileException('Input audio file URI was empty after attempting to convert to external URL.');
      }

      $sourceAudioApiParameterName = 'url';
    }

    // Don't actually start any audio processing jobs if we're in "debug mode,"
    // but do set the job IDs to fake values.
    if (Settings::isDebugModeEnabled()) {
      if ($getNewProcessedAudio) $this->setCleaningJob('abcdef');
      if ($transcribe) $this->setTranscriptionJob('123456');
      return;
    }

    $jobSubmissionApiEndpoint = Settings::getJobSubmissionApiEndpoint();
    if ($jobSubmissionApiEndpoint === '') {
      throw new ModuleConfigurationException('The "job_submission_api_endpoint" module setting is empty.');
    }
    $jobSubmissionApiRegion = Settings::getJobSubmissionApiRegion();
    if ($jobSubmissionApiRegion === '') {
      throw new ModuleConfigurationException('The "job_submission_api_region" module setting is empty.');
    }
    $processingRequestData = [
      $sourceAudioApiParameterName => $sourceAudioApiParameterValue,
      'sermon-language' => $sermonLanguageCode,
      // @todo Remove this after the API is updated to not require this.
      'transcribe' => $transcribe,
      'output-type' => ($getNewProcessedAudio ? AudioJobOutputType::USER_FACING->value : 0) | ($transcribe ? AudioJobOutputType::TRANSCRIPTION->value : 0),
      'sermon-name' => $sermonName,
      'sermon-name-normalized' => $sermonNameNormalized,
      'sermon-speaker' => $sermonSpeakerFullName,
      'sermon-speaker-normalized' => $sermonSpeakerNormalized,
      'sermon-year' => $sermonYear,
      'sermon-month' => $sermonMonth,
      'sermon-day' => $sermonDay,
      'sermon-congregation' => $sermonCongregation,
    ];
    try {
      $response = self::getApiInvoker()->callApi(
        $jobSubmissionApiEndpoint,
        $jobSubmissionApiRegion,
        $processingRequestData,
        [],
        HttpMethod::POST);
    }
    catch (GuzzleException $e) {
      $throwIfDesired(new ApiCallException('An error occurred when calling the audio processing job submission API.', $e->getCode(), $e));
      return;
    }

    $responseStatusCode = $response->getStatusCode();
    if (!self::isResponseStatusCodeValid($responseStatusCode)) {
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

    if ($getNewProcessedAudio) {
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

      $this->setCleaningJob($cleaningJobId);
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

      $this->setTranscriptionJob($transcriptionJobId);
    }

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
          $originalFid = $originalTranslation->getUnprocessedAudioId();
        }
        $newFid = NULL;
        if ($this->hasTranslation($langcode)) {
          $newTranslation = $this->getTranslation($langcode);
          $newFid = $newTranslation->getUnprocessedAudioId();
        }

        // Depending on how the original FID and new FID compare, change the
        // usage values.
        if ($originalFid !== $newFid) {
          if ($originalFid !== NULL) {
            if (!isset($usageChanges[$originalFid])) $usageChanges[$originalFid] = -1;
            else $usageChanges[$originalFid]--;
          }
          if ($newFid !== NULL) {
            if (!isset($usageChanges[$newFid])) $usageChanges[$newFid] = 1;
            else $usageChanges[$newFid]++;
          }
        }
      }
    }
    else {
      foreach ($this->iterateTranslations() as $translation) {
        $fid = $translation->getUnprocessedAudioId();
        if ($fid !== NULL) {
          if (!isset($usageChanges[$fid])) $usageChanges[$fid] = 1;
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
   * Tells whether only audio-related field data present is unprocessed audio.
   */
  public function isPureUnprocessedAudio() : bool {
    return ($this->getProcessedAudioId() === NULL
      && $this->getTranscriptionSubKey() === NULL
      && $this->getCleaningJobId() === NULL
      && $this->getTranscriptionJobId() === NULL
      && !$this->didCleaningFail()
      && !$this->didTranscriptionFail()) ? TRUE : FALSE;
  }

  /**
   * Handles any new audio cleaning result.
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
   * @param bool $newProcessedAudioObtained
   *   (output) If new processed audio was found in the results for the current
   *   job, this will be TRUE. Else, it will be FALSE.
   *
   * @return bool
   *   TRUE if this entity may have been changed, else FALSE.
   *
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a HEAD request
   *   for a new processed audio file.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Can be thrown if debug mode is enabled and the unprocessed audio file
   *   field is not set or is invalid.
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
  public function refreshProcessedAudio(bool &$newProcessedAudioObtained = FALSE) : bool {
    if (!$this->hasCleaningJob()) {
      throw new InvalidOperationException('There is no cleaning job ID associated with this entity.');
    }

    return $this->prepareToRefreshProcessedAudio($newProcessedAudioObtained)();
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
   * by this method. If the job has not finished, but has failed, the job ID is
   * unset and the job failure flag is set.
   *
   * This method performs its function even if the current transcription sub-key
   * field value is non-NULL.
   *
   * This entity is not saved in this method -- that is up to the caller.
   *
   * @param bool $newTranscriptionSubKeyObtained
   *   (output) If a new transcription XML sub-key was found in the results for
   *   the current job, this will be TRUE. Else, it will be FALSE.
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
  public function refreshTranscription(bool &$newTranscriptionSubKeyObtained = FALSE) : bool {
    if (!$this->hasTranscriptionJob()) {
      throw new InvalidOperationException('There is no transcription job ID associated with this entity.');
    }

    return $this->prepareToRefreshTranscription($newTranscriptionSubKeyObtained)();
  }

  /**
   * Unsets cleaning/transcription job IDs and records a cleaning job failure.
   */
  private function failCleaningJob() : void {
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_failed = TRUE;
    /** @phpstan-ignore-next-line */
    $this->cleaning_job_id = NULL;
    // We also unset the transcription job ID, as it is unlikely a transcription
    // operation will succeed after the cleaning job has failed.
    /** @phpstan-ignore-next-line */
    $this->transcription_job_id = NULL;
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
   * @param bool $newProcessedAudioObtained
   *   (output) If new processed audio was found in the results for the current
   *   job, this will be TRUE. Else, it will be FALSE.
   *
   * @return callable() : bool
   *   Setter returning TRUE if the entity may have been changed; else it
   *   returns FALSE.
   */
  private function prepareToRefreshProcessedAudio(bool &$newProcessedAudioObtained = FALSE) : callable {
    assert($this->hasCleaningJob());
    $newProcessedAudioObtained = FALSE;

    if (Settings::isDebugModeEnabled()) {
      $unprocessedAudioId = $this->getUnprocessedAudioId() ?? throw new InvalidOperationException('The unprocessed audio field has no value.');
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
      $response = self::getApiInvoker()->callApi(
        $cleaningJobResultsApiEndpoint,
        $cleaningJobResultsApiRegion,
        [],
        ['id' => $this->getCleaningJobId()],
        HttpMethod::GET);
    }
    catch (GuzzleException $e) {
      throw new ApiCallException('An error occurred when calling the audio cleaning job results api.', $e->getCode(), $e);
    }

    $responseStatusCode = $response->getStatusCode();
    if (!self::isResponseStatusCodeValid($responseStatusCode)) {
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
    if ($jobStatus < 0) {
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
      return fn() => FALSE;
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
      // It appears that a sort of "race condition" can occur that produces a DB
      // constraint exception due to an already-existing cache item. This is
      // likely because of the non-atomic nature of the Drupal DB backend
      // merge() method used by s3fs (there is no MERGE command in MySQL, but
      // Drupal could use SELECT...FOR UPDATE instead; it don't, however). For
      // this reason, we catch and log DB exceptions.
      try {
        $s3fsStreamWrapper->writeUriToCache($processedAudioUri);
      }
      catch (DatabaseExceptionWrapper $e) {
        Error::logException(\Drupal::logger('sermon_audio'), $e);
      }
    }
    else {
      // Get the size of the new processed audio file. We do this by making a
      // HEAD request for the file.
      $s3Client = self::getProcessedAudioS3Client();
      $result = $s3Client->headObject(['Bucket' => self::getAudioBucket(), 'Key' => self::getS3ProcessedAudioKeyPrefix() . $outputSubKey]);
      assert(is_array($result) || $result instanceof \ArrayAccess);
      if (!isset($result['ContentLength'])) {
        throw new \RuntimeException('Could not retrieve file size for processed audio file.');
      }
      $fileSize = ParseHelpers::parseInt($result['ContentLength']);
      if ($fileSize < 0) {
        throw new \RuntimeException('Invalid file size for processed audio file.');
      }
    }

    // Create the new processed audio file entity, setting its owner to the
    // owner of the unprocessed audio file, if it exists, or to the superuser,
    // if it does not. The filename is set using the output sub-key.

    if ($this->hasUnprocessedAudio()) {
      $unprocessedAudio = $this->getUnprocessedAudio(TRUE);
      if ($unprocessedAudio === NULL) $owner = 1;
      else $owner = $unprocessedAudio->getOwnerId();
    }
    else $owner = 1;

    $filename = trim(basename($outputSubKey));
    if ($filename === '') $filename = 'audio.mp3';

    $newProcessedAudioFieldInitValues = [
      'uri' => $processedAudioUri,
      'uid' => $owner,
      'filename' => $filename,
      'filemime' => 'audio/mpeg',
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

    $newProcessedAudioObtained = TRUE;
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
   * @param bool $newTranscriptionSubKeyObtained
   *   (output) If a new transcription XML sub-key was found in the results for
   *   the current job, this will be TRUE. Else, it will be FALSE.
   *
   * @return callable() : bool
   *   Setter returning TRUE if the entity may have been changed; else it
   *   returns FALSE.
   */
  private function prepareToRefreshTranscription(bool &$newTranscriptionSubKeyObtained = FALSE) : callable {
    assert($this->hasTranscriptionJob());
    $newTranscriptionSubKeyObtained = FALSE;

    if (Settings::isDebugModeEnabled()) {
      if ($this->getTranscriptionSubKey() === 'transcription.xml') {
        return function () : bool {
          $this->unsetTranscriptionJob();
          return TRUE;
        };
      }
      else {
        $newTranscriptionSubKeyObtained = TRUE;
        return function () : bool {
          $this->setTranscriptionSubKey('transcription.xml');
          $this->unsetTranscriptionJob();
          return TRUE;
        };
      }
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
      $response = self::getApiInvoker()->callApi(
        $transcriptionJobResultsApiEndpoint,
        $transcriptionJobResultsApiRegion,
        NULL,
        ['id' => $this->getTranscriptionJobId()],
        HttpMethod::GET);
    }
    catch (GuzzleException $e) {
      throw new ApiCallException('An error occurred when calling the audio transcription job results API. Message: ' . $e->getMessage(), $e->getCode(), $e);
    }

    $responseStatusCode = $response->getStatusCode();
    if (!self::isResponseStatusCodeValid($responseStatusCode)) {
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
    if ($jobStatus < 0) {
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

    if ($outputSubKey === $this->getTranscriptionSubKey()) {
      return function () : bool {
        $this->unsetTranscriptionJob();
        return TRUE;
      };
    }
    else {
      $newTranscriptionSubKeyObtained = TRUE;
      return function () use($outputSubKey) : bool {
        $this->setTranscriptionSubKey($outputSubKey);
        $this->unsetTranscriptionJob();
        return TRUE;
      };
    }
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
   * Sets the audio duration to the given value.
   */
  private function setDuration(float $value) : void {
    assert($value >= 0);
    /** @phpstan-ignore-next-line */
    $this->duration = $value;
  }

  /**
   * Sets the processed audio target ID to the given value.
   */
  private function setProcessedAudioTargetId(int $id) : void {
    /** @phpstan-ignore-next-line */
    $this->processed_audio = $id;
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
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) : array {
    $fields = parent::baseFieldDefinitions($entity_type);

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
      ->setSetting('file_extensions', 'mp4 m4a aac mp3');
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
   * Stops auto audio/transcription refreshes aft. loading sermon audio entity.
   *
   * @param int $entityId
   *   Sermon audio entity ID for which to disable automatic refreshes.
   *
   * @internal
   */
  public static function disablePostLoadAutoRefreshes(int $entityId) : void {
    self::$idsWherePostLoadIsDisabled[$entityId] = TRUE;
  }

  /**
   * Re-enables post-load auto audio/transcription refreshes.
   *
   * @see self::disablePostLoadAutoRefreshes()
   *
   * @param int $entityId
   *   Sermon audio entity ID for which to re-enable automatic refreshes.
   *
   * @internal
   */
  public static function enablePostLoadAutoRefreshes(int $entityId) : void {
    unset(self::$idsWherePostLoadIsDisabled[$entityId]);
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) : void {
    /** @var array<int, true> */
    static $finishedEntityIds = [];
    foreach ($entities as $entity) {
      if (!($entity instanceof SermonAudio)) {
        throw new \RuntimeException('Invalid entity type in $entities.');
      }

      $entityId = $entity->id();
      assert($entityId !== NULL);
      $entityId = (int) $entityId;

      if (isset(self::$idsWherePostLoadIsDisabled[$entityId])) continue;

      // Don't do anything if postLoad() has already been run for this entity.
      // This avoids various problems with the static entity cache being cleared
      // and refreshProcessedAudio() thus being called multiple times on the
      // same entity in the same request cycle (such as when it is called again
      // when the entity is saved).
      if (isset($finishedEntityIds[$entityId])) continue;

      $requiresSave = FALSE;

      // We'll have to refresh for all translations, as postLoad() is only
      // called once for all translations.
      foreach ($entity->iterateTranslations() as $translation) {
        if ($translation->hasCleaningJob()) {
          try {
            $translationAudioUpdate = $translation->prepareToRefreshProcessedAudio();
          }
          catch (\Exception $e) {
            if ($e instanceof DynamoDbException
              || $e instanceof S3Exception
              || $e instanceof EntityStorageException
              || $e instanceof InvalidSermonAudioFileException
              || $e instanceof ModuleConfigurationException
              || $e instanceof ApiCallException
              || $e instanceof ParseException) {
              // For "expected exceptions," we don't want to blow up in
              // postLoad(). Instead, we simply log the exception, and continue to
              // the next translation.
              Error::logException(\Drupal::logger('sermon_audio'), $e, level: LogLevel::WARNING);
              continue;
            }
            else throw $e;
          }
          if ($translationAudioUpdate()) $requiresSave = TRUE;
        }

        if ($translation->hasTranscriptionJob()) {
          try {
            $translationTranscriptUpdate = $translation->prepareToRefreshTranscription();
          }
          catch (\Exception $e) {
            if ($e instanceof ApiCallException || $e instanceof ModuleConfigurationException) {
              Error::logException(\Drupal::logger('sermon_audio'), $e, level: LogLevel::WARNING);
              continue;
            }
            else throw $e;
          }
          if ($translationTranscriptUpdate()) $requiresSave = TRUE;
        }
      }

      if ($requiresSave) {
        // We add the entity ID to the $finishedEntityIds set before saving.
        // This is because the save process will invoke postLoad() again (when
        // loading the unchanged entity).
        $finishedEntityIds[$entityId] = TRUE;
        $entity->save();
      }
      else {
        $finishedEntityIds[$entityId] = TRUE;
      }
    }
  }

  /**
   * "Transliterates" the given (Unicode) text to ASCII.
   *
   * @param string $text
   *   Text to transliterate.
   * @param string $langcode
   *   Language code for $text.
   * @phpstan-param non-empty-string $langcode
   *
   * @return string
   *   Transliterated text. "\\" is used for unknown characters.
   */
  private static function asciify(string $text, string $langcode) : string {
    // Try to "transliterate" the text to get an approximate ASCII
    // representation. It won't be perfect, but that's okay. Use "\" for unknown
    // characters.
    return \Drupal::transliteration()->transliterate($text, $langcode, '\\');
  }

  /**
   * Attempts to decode the $response body as JSON.
   *
   * @return ?mixed[]
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
   * Gets the AWS API invoker service.
   */
  private static function getApiInvoker() : AwsApiInvoker {
    $invoker = \Drupal::service('sermon_audio.aws_api_invoker');
    assert($invoker instanceof AwsApiInvoker);
    return $invoker;
  }

  /**
   * Gets the name of the S3 bucket for processed and unprocessed audio.
   *
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
   * Gets the file storage.
   */
  private static function getFileStorage() : FileStorageInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('file');
    assert($storage instanceof FileStorageInterface);
    return $storage;
  }

  /**
   * Gets an S3 client for the processed audio.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the "audio_s3_aws_region" module setting is missing or empty.
   */
  private static function getProcessedAudioS3Client() : S3Client {
    $region = Settings::getAudioS3Region();
    if ($region === '') {
      throw new ModuleConfigurationException('The "audio_s3_aws_region" module setting is empty.');
    }

    $factory = \Drupal::service('sermon_audio.s3_client_factory');
    assert($factory instanceof S3ClientFactory);
    return $factory->getClient($region);
  }

  /**
   * Gets the S3 key prefix for processed audio.
   *
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
   * Tells whether the given HTTP status code is in the "valid" range.
   *
   * @return bool
   *   TRUE if the response status code is in the [200..299] range; else FALSE.
   */
  private static function isResponseStatusCodeValid(int $responseStatusCode) : bool {
    return ($responseStatusCode >= 200 && $responseStatusCode < 300) ? TRUE : FALSE;
  }

  /**
   * Prepares full sermon speaker name from given sermon speaker name arguments.
   *
   * @param string $firstNames
   *   Speaker first names.
   * @param string $lastName
   *   Speaker last name.
   * @param string $firstNamesArgumentName
   *   Name of first names argument to use in exception if names are invalid.
   * @param string $lastNameArgumentName
   *   Name of last name argument to use in exception if names are invalid.
   * @param string $sermonLanguageCode
   *   Sermon language code.
   * @phpstan-param non-empty-string&lowercase-string $sermonLanguageCode
   * @param string $fullName
   *   (output) Full speaker name.
   * @param string $normalizedFullName
   *   (output) Full normalized speaker name.
   *
   * @throws \InvalidArgumentException
   */
  private static function prepareSermonSpeakerNamesFromArguments(string $firstNames,
    string $lastName,
    string $firstNamesArgumentName,
    string $lastNameArgumentName,
    string $sermonLanguageCode,
    string &$fullName,
    string &$normalizedFullName) : void {

    $firstNames = trim($firstNames);
    $lastName = trim($lastName);
    if ($firstNames === '' && $lastName === '') {
      throw new \InvalidArgumentException('Both ' . $firstNamesArgumentName . ' and ' . $lastNameArgumentName . ' are empty or are only whitespace.');
    }
    elseif ($firstNames === '') {
      $fullName = $lastName;
      $normalizedFullName = self::asciify($lastName, $sermonLanguageCode);
    }
    elseif ($lastName === '') {
      $fullName = $firstNames;
      $normalizedFullName = self::asciify($firstNames, $sermonLanguageCode);
    }
    else {
      $fullName = $firstNames . ' ' . $lastName;
      $normalizedFullName = self::asciify($lastName . ' ' . $firstNames, $sermonLanguageCode);
    }
  }

  /**
   * Throws an exception if given sermon day argument is invalid.
   *
   * @param int $day
   *   Sermon day.
   * @param string $argumentName
   *   Name of argument to use in exception.
   *
   * @throws \InvalidArgumentException
   */
  private static function throwIfInvalidSermonDay(int $day, string $argumentName) : void {
    if ($day < 1 || $day > 31) {
      throw new \InvalidArgumentException($argumentName . ' must be between 1 and 31 (inclusive).');
    }
  }

  /**
   * Throws an exception if given sermon language code is invalid.
   *
   * @param string $languageCode
   *   Sermon language code.
   * @param string $argumentName
   *   Name of argument to use in exception.
   */
  private static function throwIfInvalidSermonLanguageCode(string $languageCode, string $argumentName) : void {
    if (strlen($languageCode) !== 2) {
      throw new \InvalidArgumentException($argumentName . ' must be exactly two characters long.');
    }
  }

  /**
   * Throws an exception if given sermon month argument is invalid.
   *
   * @param int $month
   *   Sermon month.
   * @param string $argumentName
   *   Name of argument to use in exception.
   *
   * @throws \InvalidArgumentException
   */
  private static function throwIfInvalidSermonMonth(int $month, string $argumentName) : void {
    if ($month < 1 || $month > 12) {
      throw new \InvalidArgumentException($argumentName . ' must be between 1 and 12 (inclusive).');
    }
  }

}

/**
 * Represents an output type for an audio cleaning/transcription job.
 */
enum AudioJobOutputType : int {

  /**
   * Indicates user-facing audio should be outputted.
   */
  case USER_FACING = 0b01;

  /**
   * Indicates a transcription should be created.
   */
  case TRANSCRIPTION = 0b10;

}

/**
 * Represents the source to use when processing sermon audio.
 */
enum AudioProcessingSource {

  /**
   * Processed audio source.
   */
  case PROCESSED;

  /**
   * Unprocessed audio source.
   */
  case UNPROCESSED;

  /**
   * Automatically determine source: unprocessed if available, else processed.
   */
  case AUTO;

}
