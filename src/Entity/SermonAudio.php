<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaStorage;
use Drupal\sermon_audio\Exception\EntityValidationException;
use Drupal\sermon_audio\Exception\InvalidInputAudioFileException;
use Drupal\sermon_audio\Settings;
use Drupal\sermon_audio\DynamoDbClientFactory;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Ranine\Exception\AggregateException;
use Ranine\Exception\InvalidOperationException;
use Ranine\Helper\ThrowHelpers;

/**
 * An entity representing audio for a sermon.
 *
 * Audio processing of the "unprocessed audio" field can be initiated with the
 * intiateAudioProcessing() method. This method sets the
 * field_audio_processing_initiated flag.
 *
 * After a "processed audio" entity is intially loaded (this does not occur on
 * subsequent loads within the same request), a "post load" handler checks to
 * see if audio_processing_intiated is set. If the field is set and the
 * processed audio field is not set, a check is made to see if the AWS audio
 * processing job has finished. If it has, the entity's "processed audio" field
 * is updated with the processed audio file, and the entity is saved. The AWS
 * audio processing job check and subsequent processed audio field update can
 * also be forced by calling refreshProcessedAudio().
 *
 * @ContentEntityType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   base_table = "sermon_audio",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   admin_permission = "administer sermon audio",
 *   handlers = {
 *     "access" = "Drupal\sermon_audio\SermonAudioAccessControlHandler",
 *   },
 *   constraints = {
 *     "ProcessedAudioAndDurationMatchingNullity" = {}
 *   }
 * )
 */
class SermonAudio extends ContentEntityBase {

  /**
   * Gets the processed audio duration, or NULL if it is not set.
   */
  public function getDuration() : ?float {
    $value = $this->get('duration')->get(0)?->getValue();
    return $value === NULL ? NULL : (float) $value;
  }
  
  /**
   * Gets the processed audio media entity, or NULL if it is not set.
   *
   * @throws \RuntimeException
   *   Thrown if there is a reference to a processed audio media entity, but the
   *   entity was not found.
   */
  public function getProcessedAudio() : ?MediaInterface {
    $item = $this->get('processed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    $targetId = (int) $item['target_id'];
    $media = $this->getMediaStorage()->load($targetId);
    if ($media === NULL) {
      throw new \RuntimeException('Could not load media entity with ID "' . $targetId . '".');
    }
    return $media;
  }
  
  /**
   * Gets the unprocessed audio file entity, or NULL if it is not set.
   *
   * @throws \RuntimeException
   *   Thrown if there is a reference to an unprocessed audio media entity, but
   *   the entity was not found.
   */
  public function getUnprocessedAudio() : ?FileInterface {
    $item = $this->get('unprocessed_audio')->get(0)?->getValue();
    if (empty($item) || $item['target_id'] === NULL) return NULL;
    $targetId = (int) $item['target_id'];
    $file = $this->getFileStorage()->load($targetId);
    if ($file === NULL) {
      throw new \RuntimeException('Could not load file entity with ID "' . $targetId . '".');
    }
    return $file;
  }
  
  /**
   * Tells whether there exists processed audio associated with this entity.
   */
  public function hasProcessedAudio() : bool {
    $item = $this->get('processed_audio')->get(0)?->getValue();
    return (empty($item) || $item['target_id'] === NULL) ? FALSE : TRUE;
  }

  /**
   * Initiates an audio processing job corresponding to unprocessed audio file.
   *
   * Clears the "processed audio" and "duration" fields if they are set, and
   * sets field_audio_processing_intiated. If changes were made, this entity is
   * saved.
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
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the jobs table name module setting is empty.
   * @throws \InvalidArgumentException
   *   Thrown if $sermonName, $sermonSpeaker, $sermonYear, $sermonCongregation,
   *   or $outputAudioDisplayFilename is empty.
   * @throws \Ranine\Exception\AggregateException
   *   Thrown if an error occurs after a DynamoDB error while saving the entity
   *   setting a field value.
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if the job cannot be queued because it conflicts with a job
   *   already in the audio processing jobs table.
   * @throws \RuntimeException
   *   Thrown if the unprocessed audio file entity could not be loaded.
   */
  public function initiateAudioProcessing(string $sermonName,
    string $sermonSpeaker,
    string $sermonYear,
    string $sermonCongregation,
    string $outputAudioDisplayFilename) : void {
    ThrowHelpers::throwIfEmptyString($sermonName, 'sermonName');
    ThrowHelpers::throwIfEmptyString($sermonSpeaker, 'sermonSpeaker');
    ThrowHelpers::throwIfEmptyString($sermonYear, 'sermonYear');
    ThrowHelpers::throwIfEmptyString($sermonCongregation, 'sermonCongregation');
    ThrowHelpers::throwIfEmptyString($outputAudioDisplayFilename, 'outputAudioDisplayFilename');

    $unprocessedAudio = $this->getUnprocessedAudio() ?? throw static::getUnprocessedAudioFieldException();
    $inputSubKey = static::getUnprocessedAudioSubKey($unprocessedAudio);

    // Create an ouput sub-key from 1) this media entity's ID, 2) a random
    // hex sequence to ensure uniqueness, and 3) the 'm4a' extension.
    $outputSubKey = $this->id() . '-' . bin2hex(random_bytes(8)) . '.m4a';

    $didChangeEntity = FALSE;

    // Clear the "audio duration" and "processed audio" fields if necessary.
    $durationField = $this->get('duration');
    if ($durationField->get(0)?->getValue() !== NULL) {
      $durationField->removeItem(0);
      $didChangeEntity = TRUE;
    }
    $processedAudioField = $this->get('processed_audio');
    if ($processedAudioField->get(0)?->getValue() !== NULL) {
      $processedAudioField->removeItem(0);
      $didChangeEntity = TRUE;
    }

    // Indicate that we have initiated the audio processing.
    $processingInitiatedField = $this->get('processing_initiated');
    $processingInitiatedFieldItem = $processingInitiatedField->get(0);
    if ($processingInitiatedFieldItem === NULL) {
      $processingInitiatedFieldItem = $processingInitiatedField->appendItem(TRUE);
      $didChangeEntity = TRUE;
    }
    elseif (!$processingInitiatedFieldItem->getValue()) {
      $processingInitiatedFieldItem->setValue(TRUE);
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
        $processingInitiatedFieldItem->setValue(FALSE);
        $this->save();
      }
      catch (\Exception $inner) {
        throw new AggregateException('An error occurred while attempting to save the entity or set a field value, which occurred while handling a DynamoDB error.', 0,
          [$inner, $e]);
      }
      if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
        throw new InvalidOperationException('The job cannot be queued because it conflicts with an existing job.', 0, $e);
      }
      throw $e;
    }
  }

  /**
   * Tells whether the audio processing was initiated by reading field value.
   */
  public function isAudioProcessingInitiated() : bool {
    return (bool) $this->get('processing_initiated')->get(0)?->getValue();
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
    $fields['processed_audio'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Processed Audio'))
      ->setDescription(new TranslatableMarkup('Processed audio media reference.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('target_type', 'media')
      // Per https://www.drupal.org/project/commerce/issues/3137225 and
      // https://www.drupal.org/node/2576151, it seems target bundles should
      // perhaps have identical keys and values.
      ->setSetting('handler_settings', ['target_bundles' => ['audio' => 'audio']]);
    $fields['unprocessed_audio'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Unprocessed Audio'))
      ->setDescription(new TranslatableMarkup('Unprocessed audio file.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('target_type', 'file')
      ->setSetting('uri_scheme', 's3')
      ->setSetting('file_extensions', 'mp3 mp4 m4a wav aac ogg')
      ->setSetting('max_filesize', '1 GB')
      ->setSetting('file_directory', 'audio-uploads');

    return $fields;
  }

  /**
   * Gets a DynamoDB client.
   */
  private static function getDynamoDbClient() : DynamoDbClient {
    static $client;
    if (!isset($client)) {
      $dynamoDbClientFactory = \Drupal::service('sermon_audio.dynamo_db_client_factory');
      assert($dynamoDbClientFactory instanceof DynamoDbClientFactory);
      $client = $dynamoDbClientFactory->getClient();
    }
    return $client;
  }

  /**
   * Gets the file storage.
   */
  private static function getFileStorage() : FileStorageInterface {
    static $storage;
    if (!isset($storage)) {
      $storage = \Drupal::entityTypeManager()->getStorage('file');
    }
    return $storage;
  }

  /**
   * Gets the audio processing jobs DynamoDB table name.
   *
   * @return string
   *   Non-empty table name.
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
   * Gets the media entity storage.
   */
  private static function getMediaStorage() : MediaStorage {
    static $storage;
    if (!isset($storage)) {
      $storage = \Drupal::entityTypeManager()->getStorage('media');
    }
    return $storage;
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

}
