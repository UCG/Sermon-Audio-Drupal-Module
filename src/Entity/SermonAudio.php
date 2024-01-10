<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\s3fs\StreamWrapper\S3fsStream;
use Drupal\sermon_audio\Exception\EntityValidationException;
use Drupal\sermon_audio\Exception\InvalidInputAudioFileException;
use Drupal\sermon_audio\Settings;
use Drupal\sermon_audio\DynamoDbClientFactory;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\AudioHelper;
use Drupal\sermon_audio\S3ClientFactory;
use Ranine\Exception\AggregateException;
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
    $fileStorage = static::getFileStorage();
    foreach ($this->iterateTranslations() as $translation) {
      $fid = $translation->getUnprocessedAudioId();
      if ($fid !== NULL) {
        $file = $fileStorage->load($fid);
        if ($file !== NULL) {
          // Remove all usage information for this FID.
          $fileUsageManager->delete($file, 'sermon_audio', $entityTypeId, $entityId, 0);
        }
      }
    }
  }

  /**
   * Gets the processed audio duration, or NULL if it is not set.
   */
  public function getDuration() : ?float {
    $value = static::getScalarValueFromFieldItem($this->get('duration')->get(0));
    return $value === NULL ? NULL : (float) $value;
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
    return $file;
  }

  /**
   * Gets the processed audio file ID, or NULL if not set.
   */
  public function getProcessedAudioId() : ?int {
    $item = $this->get('processed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    else return (int) $item['target_id'];
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
    return $file;
  }

  /**
   * Gets the unprocessed audio file ID, or NULL if it is not set.
   */
  public function getUnprocessedAudioId() : ?int {
    $item = $this->get('unprocessed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    else return (int) $item['target_id'];
  }

  /**
   * Tells if there is a processed audio file ID associated with this entity.
   */
  public function hasProcessedAudio() : bool {
    $item = $this->get('processed_audio')->get(0)?->getValue();
    return (empty($item) || $item['target_id'] === NULL) ? FALSE : TRUE;
  }

  /**
   * Tells if there is an unprocessed audio file ID associated with this entity.
   */
  public function hasUnprocessedAudio() : bool {
    $item = $this->get('unprocessed_audio')->get(0)?->getValue();
    return (empty($item) || $item['target_id'] === NULL) ? FALSE : TRUE;
  }

  /**
   * Initiates a processing job corresponding to the unprocessed audio file.
   *
   * Clears the "processed audio" and "duration" fields if they are set, and
   * sets the "processing intiated" field. If changes were made, this entity is
   * saved. Note that if the "debug_mode" module setting is set, no actual
   * processing job is initiated.
   *
   * @param string $sermonName
   *   Sermon name to attach to processed audio.
   * @phpstan-param non-empty-string $sermonName
   * @param string $sermonSpeaker
   *   Sermon speaker to attach to processed audio.
   * @phpstan-param non-empty-string $sermonSpeaker
   * @param string $sermonYear
   *   Sermon year to attach to processed audio.
   * @phpstan-param non-empty-string $sermonYear
   * @param string $sermonCongregation
   *   Sermon congregation to attach to processed audio.
   * @phpstan-param non-empty-string $sermonCongregation
   * @param string $sermonLanguageCode
   *   Sermon language code.
   * @phpstan-param non-empty-string $sermonLanguageCode
   * @param bool $throwOnFailure
   *   TRUE if an exception should be thrown if the processing initiation fails
   *   for certain "expected" (and recoverable; that is, execution of the caller
   *   should continue without worrying about program state corruption) reasons:
   *   that is, because of 1) AWS errors, 2) validation issues with this entity,
   *   3) missing linked entiti(es), or 4) an existing (conflicting) processing
   *   job.
   * @param null|\Exception $failureException
   *   (output) If $throwOnFailure is FALSE and an "expected" exception occurs
   *   (see above), this parameter is set to the exception that occurred. This
   *   is NULL if no error occurs, or if $throwOnFailure is TRUE.
   *
   * @throws \Aws\DynamoDb\Exception\DynamoDbException
   *   Thrown if an error occurs when attempting to interface with the AWS audio
   *   processing jobs database.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when attempting to save the current entity.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path" or
   *   "jobs_db_aws_region" configuration setting is empty or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "dynamodb_timeout"
   *   configuration setting is invalid.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonName, $sermonSpeaker, $sermonYear, $sermonCongregation,
   *   or $sermonLanguageCode is empty.
   * @throws \Ranine\Exception\AggregateException
   *   Thrown if, after a DynamoDB error occurs, another error occurs in the
   *   process of setting a field value and subsequently saving the entity.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if the job cannot be queued because it conflicts with a job
   *   already in the audio processing jobs table.
   * @throws \RuntimeException
   *   Thrown if the unprocessed audio file entity could not be loaded.
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
   */
  public function initiateAudioProcessing(string $sermonName,
    string $sermonSpeaker,
    string $sermonYear,
    string $sermonCongregation,
    string $sermonLanguageCode,
    bool $throwOnFailure = TRUE,
    \Exception &$failureException = NULL) : void {
    ThrowHelpers::throwIfEmptyString($sermonName, 'sermonName');
    ThrowHelpers::throwIfEmptyString($sermonSpeaker, 'sermonSpeaker');
    ThrowHelpers::throwIfEmptyString($sermonYear, 'sermonYear');
    ThrowHelpers::throwIfEmptyString($sermonCongregation, 'sermonCongregation');
    ThrowHelpers::throwIfEmptyString($sermonLanguageCode, 'sermonLanguageCode');

    $throwIfDesired = function(\Exception $e, ?callable $alwaysThrowPredicate = NULL) use ($throwOnFailure, &$failureException) {
      if ($throwOnFailure || ($alwaysThrowPredicate !== NULL && $alwaysThrowPredicate($e))) throw $e;
      else $failureException = $e;
    };

    try {
      $unprocessedAudio = $this->getUnprocessedAudio() ?? throw static::getUnprocessedAudioFieldException();
      $inputSubKey = static::getUnprocessedAudioSubKey($unprocessedAudio);
    }
    catch (\Exception $e) {
      $throwIfDesired($e, fn($e) => $e instanceof EntityValidationException || $e instanceof InvalidInputAudioFileException || $e instanceof \RuntimeException);
      return;
    }

    $filename = substr(static::normalizeSubKeyPathSegment($sermonName, $sermonLanguageCode), 0, 128) . '.mp4';
    $outputSubKey = static::getOutputSubKey($sermonSpeaker, $sermonYear, $filename, $sermonLanguageCode);

    // Track changes to the entity, so we don't save it unnecessarily.
    $didChangeEntity = FALSE;

    // Clear the "audio duration" and "processed audio" fields if necessary.
    $durationField = $this->get('duration');
    if (!$durationField->isEmpty()) {
      $durationField->removeItem(0);
      $didChangeEntity = TRUE;
    }
    $processedAudioField = $this->get('processed_audio');
    if (!$processedAudioField->isEmpty()) {
      $processedAudioField->removeItem(0);
      $didChangeEntity = TRUE;
    }

    // Indicate that we have initiated the audio processing.
    $processingInitiatedField = $this->get('processing_initiated');
    $processingInitiatedFieldItem = $processingInitiatedField->get(0);
    if ($processingInitiatedFieldItem === NULL) {
      $processingInitiatedFieldItem = $processingInitiatedField->appendItem(['value' => TRUE]);
      $didChangeEntity = TRUE;
    }
    elseif (!static::getScalarValueFromFieldItem($processingInitiatedFieldItem)) {
      static::setScalarValueOnFieldItem($processingInitiatedFieldItem, TRUE);
      $didChangeEntity = TRUE;
    }

    if ($didChangeEntity) {
      $this->save();
    }

    // Don't actually start an audio processing job if we're in "debug mode."
    if (static::getModuleSettings()->get('debug_mode')) {
      return;
    }

    // We start a new job if one of the following conditions is met. Otherwise,
    // we throw an exception.
    // 1) No job entry exists in the AWS DB for the given sub-key.
    // 2) A job entry exists, but the job has not yet started, has already been
    // completed, or has failed. In such a case, we re-queue the job.
    // 3) An in-progress job entry exists, and the job is marked as "in
    // progress," but the start timestamp of the job indicates that the Lambda
    // function that was responsible for executing the job has already timed
    // out. In this case, we also may re-queue the job.
    $dynamoDb = static::getDynamoDbClient();
    $currentTime = \Drupal::time()->getCurrentTime();
    // Lambda jobs time out after 15 minutes; make our threshold 20 to be safe.
    $thresholdRestartTime = $currentTime - (20 * 60);
    try {
      $dynamoDb->putItem([
        'ConditionExpression'
          => 'NOT attribute_exists(#isk) OR #js = :completed OR #js = :notStarted OR #js = :failed OR (#js = :inProgress AND #st < :thresholdTime)',
        'ExpressionAttributeNames' => [
          '#isk' => 'input-sub-key',
          '#js' => 'job-status',
          '#st' => 'start-time',
        ],
        'ExpressionAttributeValues' => [
          // The "completed" job status.
          ':completed' => ['N' => '2'],
          // The "not started" job status.
          ':notStarted' => ['N' => '0'],
          // The "failed" job status.
          ':failed' => ['N' => '-1'],
          // The "in progress" job status.
          ':inProgress' => ['N' => '1'],
          ':thresholdTime' => ['N' => (string) $thresholdRestartTime],
        ],
        'Item' => [
          'input-sub-key' => ['S' => $inputSubKey],
          'output-sub-key' => ['S' => $outputSubKey],
          'queue-time' => ['N' => (string) $currentTime],
          'job-status' => ['N' => '0'],
          'sermon-name' => ['S' => $sermonName],
          'sermon-speaker' => ['S' => $sermonSpeaker],
          'sermon-year' => ['S' => $sermonYear],
          'sermon-congregation' => ['S' => $sermonCongregation],
          'sermon-language' => ['S' => $sermonLanguageCode],
          'output-display-filename' => ['S' => $filename],
        ],
        'TableName' => static::getJobsTableName(),
      ]);
    }
    catch (DynamoDbException $e) {
      // Indicate that the audio processing job has not actually successfully
      // been initiated.
      try {
        static::setScalarValueOnFieldItem($processingInitiatedFieldItem, FALSE);
        $this->save();
      }
      catch (\Exception $inner) {
        throw new AggregateException('An error occurred while attempting to save the entity or set a field value, which occurred while handling a DynamoDB error.', 0,
          [$inner, $e]);
      }
      if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
        $throwIfDesired(new InvalidOperationException('The job cannot be queued because it conflicts with an existing job.', 0, $e));
        return;
      }
      else $throwIfDesired($e);
    }
  }

  /**
   * Iterates over all translations of this entity.
   *
   * @return iterable<\Drupal\sermon_audio\Entity\SermonAudio>&\Ranine\Iteration\ExtendableIterable
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
  public function postSave(EntityStorageInterface $storage, $update = TRUE) : void {
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
    $fileStorage = static::getFileStorage();
    $entityTypeId = $this->getEntityTypeId();
    $entityId = (int) $this->id();
    foreach ($usageChanges as $fid => $change) {
      if ($change === 0) continue;
      $file = $fileStorage->load($fid);
      if ($file === NULL) continue;

      if ($change > 0) $fileUsageManager->add($file, 'sermon_audio', $entityTypeId, $entityId, $change);
      else $fileUsageManager->delete($file, 'sermon_audio', $entityTypeId, $entityId, -$change);
    }
  }

  /**
   * Attempts to correctly set the processed audio field.
   *
   * If the "debug_mode" module setting is active, then, if the processed audio
   * field is not already set to the unprocessed audio field value, it is thus
   * set, and the duration field set to zero.
   *
   * Otherwise, the processed audio field is set to point to the audio file,
   * indicated by the AWS DynamoDB database, that corresponds to the unprocessed
   * audio field. Nothing is changed if the URI computed from the DynamoDB query
   * response is the same as that currently associated with the processed audio
   * field.
   * 
   * Note that this method performs its function even if the current processed
   * audio field value is non-NULL, and even if processing_initiated is not
   * TRUE.
   *
   * This entity is not saved in this method -- that is up to the caller.
   *
   * @return bool
   *   TRUE if the processed audio or duration field was changed, else FALSE.
   *
   * @throws \Aws\DynamoDb\Exception\DynamoDbException
   *   Thrown if an error occurs when attempting to interface with the AWS audio
   *   processing jobs database.
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a HEAD request
   *   for a new processed audio file.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save a new file entity.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path",
   *   "jobs_db_aws_region", or "audio_s3_aws_region" configuration setting is
   *   empty or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "dynamodb_timeout"
   *   configuration setting is invalid.
   * @throws \Ranine\Exception\ParseException
   *   Thrown if the file size of the processsed audio file could not be parsed.
   * @throws \RuntimeException
   *   Thrown if something is wrong with the DynamoDB record returned.
   * @throws \RuntimeException
   *   Thrown if a referenced file entity does not exist.
   * @throws \RuntimeException
   *   Thrown if a new processed audio file referenced by a returned DynamoDB
   *   record did not exist when a HEAD query was made for it, or if the HEAD
   *   query response is invalid in some way.
   * @throws \RuntimeException
   *   Thrown if the "s3fs" module was enabled, but a class from that module is
   *   missing or a service from that module is missing or of the incorrect
   *   type.
   */
  public function refreshProcessedAudio() : bool {
    $newProcessedAudioId = 0;
    $newAudioDuration = 0.0;
    if ($this->prepareToRefreshProcessedAudio($newProcessedAudioId, $newAudioDuration)) {
      $this->setProcessedAudioTargetId($newProcessedAudioId);
      $this->setDuration($newAudioDuration);
      return TRUE;
    }
    else return FALSE;
  }

  /**
   * Tells whether the audio processing was initiated by reading field value.
   */
  public function wasAudioProcessingInitiated() : bool {
    return (bool) static::getScalarValueFromFieldItem($this->get('processing_initiated')->get(0));
  }

  /**
   * Attempts to get a new processed audio ID & duration for this entity.
   *
   * If the "debug_mode" module setting is active, then, if the processed audio
   * field is not already set to the unprocessed audio field value,
   * $newProcessedAudioId is thus set, and the duration is set to zero.
   *
   * Otherwise, the $newProcessedAudioId is set to point to the audio file,
   * indicated by the AWS DynamoDB database, that corresponds to the unprocessed
   * audio field. Nothing is changed if the URI computed from the DynamoDB query
   * response is the same as that currently associated with the processed audio
   * field.
   *
   * Note that this method performs its function even if the current processed
   * audio field value is non-NULL, and even if processing_initiated is not
   * TRUE.
   *
   * @param int $newProcessedAudioId
   *   (output) New processed audio ID.
   * @param float $newAudioDuration
   *   (output) New audio duration.
   *
   * @return bool
   *   TRUE if a new value (not already set on the entity field) is present in
   *   either of the output parameters, else FALSE.
   *
   * @throws \Aws\DynamoDb\Exception\DynamoDbException
   *   Thrown if an error occurs when attempting to interface with the AWS audio
   *   processing jobs database.
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a HEAD request
   *   for a new processed audio file.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save a new file entity.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path",
   *   "jobs_db_aws_region", or "audio_s3_aws_region" configuration setting is
   *   empty or invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if module's "connect_timeout" or "dynamodb_timeout"
   *   configuration setting is invalid.
   * @throws \Ranine\Exception\ParseException
   *   Thrown if the file size of the processsed audio file could not be parsed.
   * @throws \RuntimeException
   *   Thrown if something is wrong with the DynamoDB record returned.
   * @throws \RuntimeException
   *   Thrown if a referenced file entity does not exist.
   * @throws \RuntimeException
   *   Thrown if a new processed audio file referenced by a returned DynamoDB
   *   record did not exist when a HEAD query was made for it, or if the HEAD
   *   query response is invalid in some way.
   * @throws \RuntimeException
   *   Thrown if the "s3fs" module was enabled, but a class from that module is
   *   missing or a service from that module is missing or of the incorrect
   *   type.
   */
  private function prepareToRefreshProcessedAudio(int &$newProcessedAudioId, float &$newAudioDuration) : bool {
    if (static::getModuleSettings()->get('debug_mode')) {
      $unprocessedAudioId = $this->getUnprocessedAudioId() ?? throw static::getUnprocessedAudioFieldException();
      if ($this->getProcessedAudioId() === $unprocessedAudioId) {
        return FALSE;
      }
      else {
        $newProcessedAudioId = $unprocessedAudioId;
        $newAudioDuration = 0;
      }
    }
    else {
      $unprocessedAudio = $this->getUnprocessedAudio() ?? throw static::getUnprocessedAudioFieldException();
      $inputSubKey = static::getUnprocessedAudioSubKey($unprocessedAudio);
  
      $dynamoDb = static::getDynamoDbClient();
      $jobsTableName = static::getJobsTableName();
      $dbResponse = $dynamoDb->getItem([
        'Key' => [
          'input-sub-key' => ['S' => $inputSubKey],
        ],
        'ExpressionAttributeNames' => [
          '#js' => 'job-status',
          '#osk' => 'output-sub-key',
          '#odf' => 'output-display-filename',
          '#d' => 'audio-duration',
        ],
        // Grab the output display filename also, because we use it as the file
        // entity's filename.
        'ProjectionExpression' => '#js, #osk, #d, #odf',
        'TableName' => $jobsTableName,
      ]);
      if (isset($dbResponse['Item'])) {
        $item = $dbResponse['Item'];
        if (!is_array($item)) {
          throw new \RuntimeException('Jobs DB response "Item" property is of the wrong type.');
        }
        if (!isset($item['job-status']['N'])) {
          throw new \RuntimeException('Jobs DB item found does not contain valid "job-status" attribute.');
        }
        // @todo Consider doing something w/ failed jobs.
        if (((int) $item['job-status']['N']) !== 2) {
          // The job has not finished.
          return FALSE;
        }
  
        if (!isset($item['output-sub-key']['S'])) {
          throw new \RuntimeException('Jobs DB item found does not contain valid "output-sub-key" attribute.');
        }
        $outputSubKey = (string) $item['output-sub-key']['S'];
        if ($outputSubKey === '') {
          throw new \RuntimeException('The output sub-key found seems to be empty.');
        }
  
        if (!isset($item['output-display-filename']['S'])) {
          throw new \RuntimeException('Jobs DB item found does not contain valid "output-display-filename" attribute.');
        }
        $outputDisplayFilename = (string) $item['output-display-filename']['S'];
        if ($outputDisplayFilename === '') {
          throw new \RuntimeException('The output display filename found seemed to be empty.');
        }
  
        if (!isset($item['audio-duration']['N'])) {
          throw new \RuntimeException('Jobs DB items found does not contain valid "audio-duration" attribute.');
        }
        $newAudioDuration = (float) $item['audio-duration']['N'];
        if (!is_finite($newAudioDuration) || $newAudioDuration < 0) {
          throw new \RuntimeException('The audio duration was not finite or was negative.');
        }
      }
      // Otherwise, there is no job with the given input sub-key.
      else return FALSE;
  
      assert(isset($outputSubKey));
      assert(isset($outputDisplayFilename));
      assert($outputSubKey != "");
      assert($outputDisplayFilename != "");
      assert(is_finite($newAudioDuration) && $newAudioDuration >= 0);
  
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
      if (\Drupal::moduleHandler()->isLoaded('s3fs')) {
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
        $s3Client = static::getS3Client();
        $result = $s3Client->headObject(['Bucket' => static::getAudioBucket(), 'Key' => static::getS3ProcessedAudioKeyPrefix() . $outputSubKey]);
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
      $newProcessedAudio = static::getFileStorage()
        ->create($newProcessedAudioFieldInitValues)
        ->enforceIsNew();
      $newProcessedAudio->save();
      $newProcessedAudioId = (int) $newProcessedAudio->id();
    }

    return TRUE;
  }

  /**
   * Sets the audio duration to the given value.
   */
  private function setDuration(float $value) : void {
    $durationField = $this->get('duration');
    if ($durationField->count() === 0) $durationField->appendItem(['value' => $value]);
    else static::setScalarValueOnFieldItem($durationField->get(0), $value);
  }

  /**
   * Sets the processed audio target ID to the given value.
   */
  private function setProcessedAudioTargetId(int $id) : void {
    $processedAudioField = $this->get('processed_audio');
    // Get the first item, or create it if necessary.
    if ($processedAudioField->count() === 0) {
      $processedAudioField->appendItem([]);
    }
    $processedAudioItem = $processedAudioField->get(0);
    assert($processedAudioItem instanceof FieldItemBase);
    // Reset the item to its default value.
    $processedAudioItem->applyDefaultValue();
    // Finally, set the target entity ID.
    $processedAudioItem->set('target_id', $id);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) : array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['duration'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Duration'))
      ->setDescription(new TranslatableMarkup('Duration of processed sermon audio.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 0);
    $fields['processing_initiated'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Processing Initiated'))
      ->setDescription(new TranslatableMarkup('Whether processing of the unprocessed audio has yet been initiated.'))
      ->setCardinality(1)
      ->setTranslatable(TRUE)
      ->setRequired(FALSE)
      ->setDefaultValue(FALSE)
      ->setSetting('on_label', 'On')
      ->setSetting('off_label', 'Off');
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
        if (!AudioHelper::isProcessedAudioRefreshable($translation)) continue;

        $newProcessedAudioId = 0;
        $newAudioDuration = 0.0;
        try {
          $translationShouldBeRefreshed = $translation->prepareToRefreshProcessedAudio($newProcessedAudioId, $newAudioDuration);
        }
        catch (\Exception $e) {
          if ($e instanceof DynamoDbException
            || $e instanceof S3Exception
            || $e instanceof EntityStorageException
            || $e instanceof EntityValidationException
            || $e instanceof InvalidInputAudioFileException
            || $e instanceof ModuleConfigurationException
            || $e instanceof ParseException) {
            // For "expected exceptions," we don't want to blow up in
            // postLoad(). Instead, we simply log the exception, and continue to
            // the next translation.
            watchdog_exception('sermon_audio', $e);
            continue;
          }
          else throw $e;
        }
        if ($translationShouldBeRefreshed) {
          $translation->setProcessedAudioTargetId($newProcessedAudioId);
          $translation->setDuration($newAudioDuration);
          $requiresSave = TRUE;
        }
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
   * Gets a DynamoDB client.
   */
  private static function getDynamoDbClient() : DynamoDbClient {
    $dynamoDbClientFactory = \Drupal::service('sermon_audio.dynamo_db_client_factory');
    assert($dynamoDbClientFactory instanceof DynamoDbClientFactory);
    return $dynamoDbClientFactory->getClient();
  }

  /**
   * Gets the file storage.
   */
  private static function getFileStorage() : FileStorageInterface {
    return \Drupal::entityTypeManager()->getStorage('file');
  }

  /**
   * Gets the audio processing jobs DynamoDB table name.
   *
   * @return string
   *   Table name.
   * @phpstan-return non-empty-string
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the jobs table name module setting is empty.
   */
  private static function getJobsTableName() : string {
    $tableName = Settings::getJobsTableName();
    if ($tableName === '') {
      throw new ModuleConfigurationException('The jobs table name module setting is empty.');
    }
    return $tableName;
  }

  /**
   * Gets this module's settings.
   */
  private static function getModuleSettings() : ImmutableConfig {
    return \Drupal::config('sermon_audio.settings');
  }

  /**
   * Gets the output sub-key for the given sermon audio parameters.
   *
   * @param string $sermonSpeaker
   *   Sermon speaker.
   * @phpstan-param non-empty-string $sermonSpeaker
   * @param string $sermonYear
   *   Sermon year.
   * @phpstan-param non-empty-string $sermonYear
   * @param string $outputFilename
   *   Output filename (normalized).
   * @phpstan-param non-empty-string $outputFilename
   * @param string $sermonLanguage
   *   Sermon language.
   * @phpstan-param non-empty-string $sermonLanguage
   *
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
   */
  private static function getOutputSubKey(string $sermonSpeaker, string $sermonYear, string $outputFilename, string $sermonLanguage) : string {
    assert($sermonSpeaker !== '');
    assert($sermonYear !== '');
    assert($outputFilename !== '');
    assert($sermonLanguage !== '');

    $normalizedSermonYear = static::normalizeSubKeyPathSegment($sermonYear, $sermonLanguage);
    $normalizedSermonSpeaker = static::normalizeSubKeyPathSegment($sermonSpeaker, $sermonLanguage);
    return substr($normalizedSermonYear, 0, 16) . '/'
      . substr($normalizedSermonSpeaker, 0, 128) . '/'
      . $outputFilename . '-'
      // Include a random hexadecimal sequence for uniqueness.
      . bin2hex(random_bytes(8)) . '/'
      . $outputFilename;
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
   * Gets the value ("value" property) from a field item of a scalar type.
   *
   * @param \Drupal\Core\Field\FieldItemInterface|null $item
   *   Field item, or NULL if no field item (in that case, this method will just
   *   return NULL -- this is just for convenience).
   *
   * @return mixed
   *   Item value, or NULL if item value is not defined.
   */
  private static function getScalarValueFromFieldItem(?FieldItemInterface $item) : mixed {
    if ($item === NULL) return NULL;
    $fullValue = $item->getValue();
    return $fullValue ? $fullValue['value'] : NULL;
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
   * Normalizes a path segment in an output sub-key.
   *
   * Converts the segment to ASCII, removes/replaces non-alaphnumeric
   * characters, and makes everything lowercase.
   *
   * @param string $segment
   * @param string $langcode
   *   Language code of segment language.
   * @phpstan-param non-empty-string $langcode
   *
   * @return string
   *   Normalized segment.
   *
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
   */
  private static function normalizeSubKeyPathSegment(string $segment, string $langcode) : string {
    // Try to "transliterate" the segment to get an approximate ASCII
    // representation. It won't be perfect, but that's okay. Use "\" for unknown
    // characters to ensure these characters don't get merged with whitespace,
    // etc. in the processing below (and are instead later replaced with "-").
    $normalizedSegment = \Drupal::transliteration()->transliterate($segment, $langcode, '\\');
    // Now, merge together sequences of spaces and certain punctuation/special
    // characters ((space)-_!?.,;"/@~*()'$%), and replace them with empty
    // strings on the ends of the string, and dashes in the middle.
    $normalizedSegment = preg_replace([
      // Beginning of string.
      '/^(?:[-_!?.,;"\/@~*()\'#$%]|\s)+/',
      // End.
      '/(?:[-_!?.,;"\/@~*()\'#$%]|\s)+$/',
      // Middle.
      '/(?:[-_!?.,;"\/@~*()\'#$%]|\s)+/',
    ], ['', '', '-'], $normalizedSegment) ?? throw new \RuntimeException('A regex error occurred.');
    // Now convert remaining non-alphanumeric characters to "-", and make
    // everything lowercase.
    $normalizedSegment = preg_replace('/[^-a-z0-9]/i', '-', $normalizedSegment)
      ?? throw new \RuntimeException('A regex error occurred.');
    return strtolower($normalizedSegment);
  }

  /**
   * Sets the core value (defined by "value" proeprty) for scalar field item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   Field item.
   * @param mixed $value
   *   Scalar value.
   */
  private static function setScalarValueOnFieldItem(FieldItemInterface $item, $value) : void {
    $item->setValue(['value' => $value]);
  }

}
