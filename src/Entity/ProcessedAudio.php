<?php

declare (strict_types = 1);

namespace Drupal\processed_audio_entity\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\media\Entity\Media;
use Drupal\processed_audio_entity\Exception\EntityValidationException;
use Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException;
use Drupal\processed_audio_entity\Exception\ModuleConfigurationException;
use Drupal\processed_audio_entity\Settings;
use Ranine\Exception\AggregateException;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ThrowHelpers;

/**
 * Represents a "processed audio" bundle of a media entity.
 *
 * Audio processing of the "unprocessed audio" field can be initiated with the
 * intiateAudioProcessing() method. This method sets the flag described by
 * field_audio_processing_initiated.
 *
 * After a "processed audio" entity is intially loaded (this does not occur on
 * subsequent loads), a "post load" handler checks to see if
 * field_audio_processing_intiated is set. If the field is set and the processed
 * audio field is not set, a check is made to see if the AWS audio processing
 * job has finished. If it has, the entity's "processed audio" field is updated
 * with the processed audio file, and the entity is saved. This procedure can
 * also be forced by calling refreshProcessedAudio().
 */
class ProcessedAudio extends Media {

  /**
   * Gets the audio duration field value, or NULL if it is not set.
   */
  public function getAudioDuration() : ?float {
    $value = $this->get('field_duration')->get(0)?->getValue();
    return $value === NULL ? NULL : (float) $value;
  }

  /**
   * Gets the processed audio FID, or NULL if it is not set.
   */
  public function getProcessedAudioFid() : ?int {
    $item = $this->get('field_processed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    return (int) $item['target_id'];
  }

  /**
   * Loads/returns processed audio file entity, or NULL if the field isn't set.
   *
   * @throws \RuntimeException
   *   Thrown if the file entity is not found.
   */
  public function getProcessedAudioFile() : ?FileInterface {
    $fid = $this->getProcessedAudioFid();
    if ($fid === NULL) return NULL;
    return static::getFileStorage()->load($fid)
      ?? throw new \RuntimeException('Could not locate processed audio file entity.');
  }

  /**
   * Gets the unprocessed audio FID, or NULL if it is not set.
   */
  public function getUnprocessedAudioFid() : ?int {
    $item = $this->get('field_unprocessed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    return (int) $item['target_id'];
  }

  /**
   * Loads/returns unprocessed audio file entity, or NULL if field isn't set.
   *
   * @throws \RuntimeException
   *   Thrown if the file entity is not found.
   */
  public function getUnprocessedAudioFile() : ?FileInterface {
    $fid = $this->getUnprocessedAudioFid();
    if ($fid === NULL) return NULL;
    return static::getFileStorage()->load($fid)
      ?? throw new \RuntimeException('Could not locate unprocessed audio file entity.');
  }

  /**
   * Initiates an audio processing job corresponding to unprocessed audio file.
   *
   * Clears the "processed audio" and "duration" fields if they are set.
   *
   * @param string $sermonName
   *   Sermon name to attach to processed audio.
   * @param string $sermonSpeaker
   *   Sermon speaker to attach to processed audio.
   * @param string $sermonYear
   *   Sermon year to attach to processed audio.
   * @param string $sermonCongregation
   *   Sermon congregation to attach to processed audio.
   * @param string $outputAudioDisplayFilename
   *   Display filename to use for processed audio (this is the filename that a
   *   user who downloads the audio file will see).
   *
   * @throws \Aws\DynamoDb\Exception\DynamoDbException
   *   Thrown if an error occurs when attempting to interface with the AWS audio
   *   processing jobs database.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when attempting to save the current entity.
   * @throws \Drupal\processed_audio_entity\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\processed_audio_entity\Exception\ModuleConfigurationException
   *   Thrown if the jobs table name module setting is empty.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonName, $sermonSpeaker, $sermonYear, $sermonCongregation,
   *   or $outputAudioDisplayFilename is empty.
   * @throws \Ranine\Exception\AggregateException
   *   Thrown if an error occurs while saving the entity after a DynamoDB error.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if the job cannot be queued because it conflicts with a job
   *   already in the audio processing jobs table.
   * @throws \RuntimeException
   *   Thrown if the unprocessed audio file entity could not be loaded.
   */
  public function initiateAudioProcessing(string $sermonName, string $sermonSpeaker, string $sermonYear, string $sermonCongregation, string $outputAudioDisplayFilename) : void {
    ThrowHelpers::throwIfEmptyString($sermonName, 'sermonName');
    ThrowHelpers::throwIfEmptyString($sermonSpeaker, 'sermonSpeaker');
    ThrowHelpers::throwIfEmptyString($sermonYear, 'sermonYear');
    ThrowHelpers::throwIfEmptyString($sermonCongregation, 'sermonCongregation');
    ThrowHelpers::throwIfEmptyString($outputAudioDisplayFilename, 'outputAudioDisplayFilename');

    $unprocessedAudioFile = $this->getUnprocessedAudioFile() ?? throw static::getUnprocessedAudioFieldException();
    $inputSubKey = static::getUnprocessedAudioSubKey($unprocessedAudioFile);

    // Create an ouput sub-key from 1) this media entity's ID, 2) a random
    // hex sequence to ensure uniqueness, and 3) the 'm4a' extension.
    $outputSubKey = $this->id() . '-' . bin2hex(random_bytes(8)) . '.m4a';

    // Clear the "audio duration" and "processed audio" fields if necessary.
    $didChangeEntity = FALSE;
    $durationField = $this->get('field_duration');
    if ($durationField->get(0)?->getValue() !== NULL) {
      $durationField->removeItem(0);
      $didChangeEntity = TRUE;
    }
    $processedAudioField = $this->get('field_processed_audio');
    if ($processedAudioField->get(0)?->getValue() !== NULL) {
      $processedAudioField->removeItem(0);
      $didChangeEntity = TRUE;
    }
    // Indicate that we have initiated the audio processing process.
    $audioProcessingInitiatedField = $this->get('field_audio_processing_initiated');
    $audioProcessingInitiatedFieldItem = $audioProcessingInitiatedField->get(0);
    if ($audioProcessingInitiatedFieldItem === NULL) {
      $audioProcessingInitiatedFieldItem = $audioProcessingInitiatedField->appendItem(TRUE);
      $didChangeEntity = TRUE;
    }
    elseif (!$audioProcessingInitiatedFieldItem->getValue()) {
      $audioProcessingInitiatedFieldItem->setValue(TRUE);
      $didChangeEntity = TRUE;
    }

    if ($didChangeEntity) {
      $this->save();
    }

    // We start a new job if one of the following conditions is met. Otherwise,
    // we throw an exception:
    // 1) No job entry exists in the AWS DB for the given sub-key.
    // 2) A job entry exists, but the job has not yet started, has already been
    // completed, or has failed. In such a case, we re-queue the job.
    // 3) An in-progress job entry exists, and the job is marked as "in
    // progress," but the start timestamp of the job indicates that the Lambda
    // function that was responsible for executing the job has already timed
    // out. In this case, we may likewise re-queue the job.
    $dynamoDb = static::getDynamoDbClient();
    $currentTime = \Drupal::time()->getCurrentTime();
    // Lambda jobs time out after 15 minutes; make it 20 to be safe.
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
          'output-display-filename' => ['S' => $outputAudioDisplayFilename],
        ],
        'TableName' => static::getJobsTableName(),
      ]);
    }
    catch (DynamoDbException $e) {
      // Indicate that the audio processing job has not actually successfully
      // been initiated.
      try {
        $audioProcessingInitiatedFieldItem->setValue(FALSE);
        $this->save();
      }
      catch (\Exception $inner) {
        throw new AggregateException('An error occurred while attempting to save the entity, which occurred while handling a DynamoDB error.', 0,
          [$inner, $e]);
      }
      if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
        throw new InvalidOperationException('The job cannot be queued because it conflicts with an existing job.', 0, $e);
      }
      throw $e;
    }
  }

  /**
   * Attempts to set the processed audio field.
   *
   * The processed audio field is set to the value, indicated by the AWS
   * DynamoDB database, that corresponds to the unprocessed audio field. Nothing
   * is changed if the URI computed from the DynamoDB query response is the same
   * as that currently associated with the processed audio field.
   * 
   * Note that this method still performs its function even if the current
   * processed audio field value is non-NULL, and even if
   * field_audio_processing_initiated is not TRUE.
   *
   * This entity is not saved after the processed audio field is set -- that is
   * up to the caller.
   *
   * @return bool
   *   TRUE if the processed audio field was updated, else FALSE.
   *
   * @throws \Aws\DynamoDb\Exception\DynamoDbException
   *   Thrown if an error occurs when attempting to interface with the AWS audio
   *   processing jobs database.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown if an error occurs when trying to save this or another entity.
   * @throws \Drupal\processed_audio_entity\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \RuntimeException
   *   Thrown if something is wrong with the DynamoDB record returned.
   * @throws \RuntimeException
   *   Thrown if a referenced file entity does not exist.
   */
  public function refreshProcessedAudio() : bool {
    $unprocessedAudioFile = $this->getUnprocessedAudioFile() ?? throw static::getUnprocessedAudioFieldException();
    $inputSubKey = static::getUnprocessedAudioSubKey($unprocessedAudioFile);

    $dynamoDb = static::getDynamoDbClient();
    $jobsTableName = static::getJobsTableName();
    $dbResponse = $dynamoDb->getItem([
      'Key' => [
        'input-sub-key' => ['S' => $inputSubKey],
      ],
      'ExpressionAttributeNames' => [
        '#js' => 'job-status',
        '#osk' => 'output-sub-key',
        '#d' => 'audio-duration',
      ],
      'ProjectionExpression' => '#js, #osk, #d',
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
      if (((int) $item['job-status']['N']) !== 2) {
        // The job has not completed.
        return FALSE;
      }

      if (!isset($item['output-sub-key']['S'])) {
        throw new \RuntimeException('Jobs DB item found does not contain valid "output-sub-key" attribute.');
      }
      $outputSubKey = (string) $item['output-sub-key']['S'];
      if ($outputSubKey === '') {
        throw new \RuntimeException('The output sub-key found seemed to be empty.');
      }

      if (!isset($item['audio-duration']['N'])) {
        throw new \RuntimeException('Jobs DB items found does not contain valid "audio-duration" attribute.');
      }
      $audioDuration = (float) $item['audio-duration']['N'];
      if (!is_finite($audioDuration) || $audioDuration < 0) {
        throw new \RuntimeException('The audio duration was not finite or was negative.');
      }
    }
    // Otherwise, there is no job with the given input sub-key.
    else return FALSE;

    assert(isset($audioDuration));
    assert(isset($outputSubKey));
    assert($outputSubKey != "");
    assert(is_finite($audioDuration) && $audioDuration >= 0);

    // Assemble the full URI and create a corresponding file entity, if
    // necessary.
    if (!isset($outputPrefix)) {
      $outputPrefix = Settings::getProcessedAudioUriPrefix();
    }
    $processedAudioUri = $outputPrefix . $outputSubKey;
    // Before creating the file entity, check to see if the current processed
    // audio entity already has the correct URI.
    $currentProcessedAudio = $this->getProcessedAudioFile();
    if ($currentProcessedAudio?->get('field_processed_audio')->getValue() === $processedAudioUri) {
      return FALSE;
    }

    // @todo See if owner and/or other metadata needs to be set here.
    $newProcessedAudio = static::getFileStorage()->create([
      'uri' => $processedAudioUri,
      'filename' => basename($processedAudioUri),
      'filemime' => 'audio/m4a',
      'status' => TRUE,
    ])->enforceIsNew();
    $newProcessedAudio->save();

    // Link the new file to this media entity.
    $processedAudioField = $this->get('field_processed_audio');
    // Get the first item, or create it if necessary.
    if ($processedAudioField->count() === 0) {
      $processedAudioField->appendItem([]);
    }
    $processedAudioItem = $processedAudioField->get(0);
    assert($processedAudioItem instanceof EntityReferenceItem);
    // Reset the item to its default value.
    $processedAudioItem->applyDefaultValue();
    // Finally, set the target entity ID.
    $processedAudioItem->set('target_id', $newProcessedAudio->id());

    // Set the audio duration.
    $durationField = $this->get('field_duration');
    if ($durationField->count() === 0) $durationField->appendItem($audioDuration);
    else $durationField->get(0)->setValue($audioDuration);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) : void {
    /** @var null[] */
    static $finishedEntityIds = [];
    foreach ($entities as $entity) {
      // If 1) postLoad() hasn't been run for $entity, 2) the processed audio
      // field isn't already set, and 3) field_audio_processing_initiated is
      // set, then we call refreshProcessedAudio() on the entity. Condition 1)
      // avoids various problems with the static entity cache being cleared and
      // refreshProcessedAudio() being called multiple times on the same entity
      // in the same request cycle (such as when it is called again when the
      // entity is saved).

      $entityId = $entity->id();
      // Condition 1:
      if (array_key_exists($entity->id(), $finishedEntityIds)) continue;

      if (!($entity instanceof ProcessedAudio)) {
        throw new \InvalidArgumentException('Invalid entity type in $entities.');
      }
      /** @var \Drupal\processed_audio_entity\Entity\ProcessedAudio $entity */

      // Condition 2:
      if ($entity->getProcessedAudioFid() !== NULL) continue;
      // Condition 3:
      if (!$entity->get('field_audio_processing_initiated')->getValue()) continue;

      
      if ($entity->refreshProcessedAudio()) {
        $finishedEntityIds[$entityId] = NULL;
        // We add the entity ID to the $finishedEntityIds set before saving.
        // This is because the save process will invoke postLoad() again when
        // loading the unchanged entity.
        $finishedEntityIds[$entityId] = NULL;
        $entity->save();
      }
      else {
        $finishedEntityIds[$entityId] = NULL;
      }
    }
  }

  /**
   * Gets a DynamoDB client.
   */
  private static function getDynamoDbClient() : DynamoDbClient {
    /** @var \Drupal\processed_audio_entity\DynamoDbClientFactory */
    $dynamoDbClientFactory = \Drupal::service('processed_audio_entity.dynamo_db_client_factory');
    return $dynamoDbClientFactory->getClient();
  }

  /**
   * Gets the file storage.
   */
  private static function getFileStorage() : FileStorageInterface {
    static $fileStorage;
    if (!isset($fileStorage)) {
      $fileStorage = \Drupal::entityTypeManager()->getStorage('file');
    }
    return $fileStorage;
  }

  /**
   * Gets the audio processing jobs DynamoDB table name.
   *
   * @return string
   *   Non-empty table name.
   *
   * @throws \Drupal\processed_audio_entity\Exception\ModuleConfigurationException
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
   * Gets a new exception indicating the unprocessed audio file doesn't exist.
   */
  private static function getUnprocessedAudioFieldException() : \Exception {
    return new EntityValidationException('The unprocessed audio field has no value.');
  }

  /**
   * Gets the input sub-key from the URI of the given file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   Unprocessed audio file entity.
   *
   * @throws \Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   */
  private static function getUnprocessedAudioSubKey(FileInterface $file) : string {
    $uri = $file->getFileUri();

    $prefix = Settings::getUnprocessedAudioUriPrefix();
    if (!str_starts_with($uri, $prefix)) {
      throw new InvalidInputAudioFileException('Input audio file prefix was incorrect.');
    }

    // Extract the sub-key from the input URI, if possible.
    $inputSubKey = substr($uri, strlen($prefix));
    if (!is_string($inputSubKey) || $inputSubKey === '') {
      throw new InvalidInputAudioFileException('Input audio file URI has an empty or invalid sub-key.');
    }

    return $inputSubKey;
  }

}
