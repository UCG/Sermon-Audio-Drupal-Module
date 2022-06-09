<?php

declare (strict_types = 1);

namespace Drupal\processed_audio_entity\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\media\Entity\Media;
use Drupal\processed_audio_entity\Exception\EntityValidationException;
use Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException;
use Drupal\processed_audio_entity\Exception\ModuleConfigurationException;
use Drupal\processed_audio_entity\Settings;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ThrowHelpers;

/**
 * Represents a "processed audio" bundle of a media entity.
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
   * Gets the unprocessed audio FID, or NULL if it is not set.
   */
  public function getUnprocessedAudioFid() : ?int {
    $item = $this->get('field_unprocessed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    return (int) $item['target_id'];
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

    $unprocessedAudioFid = $this->getUnprocessedAudioFid() ?? throw static::getUnprocessedAudioFieldException();
    $inputSubKey = static::getUnprocessedAudioSubKeyFromFid($unprocessedAudioFid);

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
      if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
        throw new InvalidOperationException('The job cannot be queued because it conflicts with an existing job.', 0, $e);
      }
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) : void {
    foreach ($entities as $entity) {
      if (!($entity instanceof ProcessedAudio)) {
        throw new \InvalidArgumentException('Invalid entity type in $entities.');
      }
      /** @var \Drupal\processed_audio_entity\Entity\ProcessedAudio $entity */

      // If the processed audio field isn't set, query the audio processing jobs
      // DB to see if there is now a corresponding processed audio file. If so,
      // create a corresponding file entity, link it to this entity, and set the
      // audio duration field.
      if ($entity->getProcessedAudioFid() !== NULL) continue;

      $inputSubKey = static::getUnprocessedAudioSubKeyFromFid($entity->getUnprocessedAudioFid()
        ?? throw static::getUnprocessedAudioFieldException());
      if (!isset($dynamoDb)) {
        $dynamoDb = static::getDynamoDbClient();
      }
      if (!isset($jobsTableName)) {
        $jobsTableName = static::getJobsTableName();
      }
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
          // The job has not completed. Just move on...
          continue;
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
      // Otherwise, there is no job with the given input sub-key. Move on...
      else continue;

      assert(isset($audioDuration));
      assert(isset($outputSubKey));
      assert($outputSubKey != "");
      assert(is_finite($audioDuration) && $audioDuration >= 0);

      // Assemble the full URI and create a corresponding file entity.
      if (!isset($outputPrefix)) {
        $outputPrefix = Settings::getProcessedAudioUriPrefix();
      }
      $processedAudioUri = $outputPrefix . $outputSubKey;
      if (!isset($fileStorage)) {
        $fileStorage = \Drupal::entityTypeManager()->getStorage('file');
      }
      // @todo See if owner and/or other metadata needs to be set here.
      $file = $fileStorage->create([
        'uri' => $processedAudioUri,
        'filename' => basename($processedAudioUri),
        'filemime' => 'audio/m4a',
        'status' => TRUE,
      ])->enforceIsNew();
      $file->save();

      // Link the new file to this media entity.
      $processedAudioField = $entity->get('field_processed_audio');
      // Get the first item, or create it if necessary.
      if ($processedAudioField->count() === 0) {
        $processedAudioField->appendItem([]);
      }
      $processedAudioItem = $processedAudioField->get(0);
      assert($processedAudioItem instanceof EntityReferenceItem);
      // Reset the item to its default value.
      $processedAudioItem->applyDefaultValue();
      // Finally, set the target entity ID.
      $processedAudioItem->set('target_id', $file->id());

      // Set the audio duration.
      $durationField = $entity->get('field_duration');
      if ($durationField->count() === 0) $durationField->appendItem($audioDuration);
      else $durationField->get(0)->setValue($audioDuration);

      $entity->save();
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
   * Gets the input sub-key from the file corresponding to the given FID.
   *
   * @param int $fid
   *   FID of unprocessed audio file entity for which to retrieve sub-key.
   *
   * @throws \Drupal\processed_audio_entity\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \RuntimeException
   *   Thrown if the file entity could not be loaded.
   */
  private static function getUnprocessedAudioSubKeyFromFid(int $fid) : string {
    // Load the file entity in order to get the URI.
    /** @var \Drupal\file\FileStorageInterface */
    $fileStorage = \Drupal::entityTypeManager()->getStorage('file');
    /** @var \Drupal\file\FileInterface */
    $inputFile = $fileStorage->load($fid)
      ?? throw new \RuntimeException('Could not locate unprocessed audio file entity.');

    $inputUri = $inputFile->getFileUri();

    $prefix = Settings::getUnprocessedAudioUriPrefix();
    if (!str_starts_with($inputUri, $prefix)) {
      throw new InvalidInputAudioFileException('Input audio file prefix was incorrect.');
    }

    // Extract the sub-key from the input URI, if possible.
    $inputSubKey = substr($inputUri, strlen($prefix));
    if (!is_string($inputSubKey) || $inputSubKey === '') {
      throw new InvalidInputAudioFileException('Input audio file URI has an empty or invalid sub-key.');
    }

    return $inputSubKey;
  }

}
