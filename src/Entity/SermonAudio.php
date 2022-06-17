<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemBase;
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
 * intiateAudioProcessing() method. This method first sets the
 * processing_initiated flag.
 *
 * After a "processed audio" entity is intially loaded (this does not occur on
 * subsequent loads within the same request), a "post load" handler checks to
 * see if processing_intiated is set. If the field is set and the processed
 * audio field is not set, a check is made to see if the AWS audio processing
 * job has finished. If it has, the entity's "processed audio" field is updated
 * with a new media entity referencing the processed audio file, and the entity
 * is saved. The AWS audio processing job check and subsequent processed audio
 * field update can also be forced by calling refreshProcessedAudio().
 *
 * @ContentEntityType(
 *   id = "sermon_audio",
 *   label = @Translation("Sermon Audio"),
 *   base_table = "sermon_audio",
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
 *     "ProcessedAudioAndDurationMatchingNullity" = {}
 *   },
 *   translatable = TRUE,
 *   links = {},
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
   * Initiates a processing job corresponding to the unprocessed audio file.
   *
   * Clears the "processed audio" and "duration" fields if they are set, and
   * sets the "processing intiated" field. If changes were made, this entity is
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
   *   user who downloads the audio file will see) -- this is also used later
   *   as the name of the processed audio media entity.
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
   *   Thrown if, after a DynamoDB error occurs, another error occurs in th
   *   process of setting a field value and subsequently saving the entity.
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

    // Create an ouput sub-key from 1) this entity's ID, 2) a random hex
    // sequence to ensure uniqueness, and 3) the 'm4a' extension.
    $outputSubKey = $this->id() . '-' . bin2hex(random_bytes(8)) . '.m4a';

    // Track changes to the entity, so we don't save it unnecessarily.
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
    // we throw an exception.
    // 1) No job entry exists in the AWS DB for the given sub-key.
    // 2) A job entry exists, but the job has not yet started, has already been
    // completed, or has failed. In such a case, we re-queue the job.
    // 3) An in-progress job entry exists, and the job is marked as "in
    // progress," but the start timestamp of the job indicates that the Lambda
    // function that was responsible for executing the job has already timed
    // out. In this case, we may likewise re-queue the job.
    $dynamoDb = static::getDynamoDbClient();
    $currentTime = \Drupal::time()->getCurrentTime();
    // The Lambda jobs time out after 15 minutes; make it 20 to be safe.
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
   * Attempts to correctly set the processed audio field.
   *
   * The processed audio field is set to point to the audio file, indicated by
   * the AWS DynamoDB database, that corresponds to the unprocessed audio field.
   * Nothing is changed if the URI computed from the DynamoDB query response is
   * the same as that currently associated with the processed audio field.
   * 
   * Note that this method still performs its function even if the current
   * processed audio field value is non-NULL, and even if processing_initiated
   * is not TRUE.
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
   *   Thrown if an error occurs when trying to save a new file or media entity.
   * @throws \Drupal\sermon_audio\Exception\EntityValidationException
   *   Thrown if the unprocessed audio file field is not set.
   * @throws \Drupal\sermon_audio\Exception\InvalidInputAudioFileException
   *   Thrown if the input audio file URI does not have the correct prefix (as
   *   defined in the module settings) or is otherwise invalid.
   * @throws \RuntimeException
   *   Thrown if something is wrong with the DynamoDB record returned.
   * @throws \RuntimeException
   *   Thrown if a referenced file entity does not exist.
   */
  public function refreshProcessedAudio() : bool {
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
      // Grab the output display filename also, because we use it as the media
      // name.
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
      $audioDuration = (float) $item['audio-duration']['N'];
      if (!is_finite($audioDuration) || $audioDuration < 0) {
        throw new \RuntimeException('The audio duration was not finite or was negative.');
      }
    }
    // Otherwise, there is no job with the given input sub-key.
    else return FALSE;

    assert(isset($outputSubKey));
    assert(isset($audioDuration));
    assert(isset($outputDisplayFilename));
    assert($outputSubKey != "");
    assert(is_finite($audioDuration) && $audioDuration >= 0);

    $processedAudioUri = Settings::getProcessedAudioUriPrefix() . $outputSubKey;
    // Before creating the media and file entities, check to see if the current
    // processed audio entity already references the correct URI.
    $processedAudio = $this->getProcessedAudio();
    if ($processedAudio !== NULL) {
      $processedAudioFile = $processedAudio->getSource()->getSourceFieldValue($processedAudio);
      if ($processedAudioFile instanceof FileInterface
        && $processedAudioFile->getFileUri() === $processedAudioUri) {
        return FALSE;
      }
    }

    // Create the new processed audio file entity, and then create a new
    // corresponding media entity.
    $newProcessedAudioFile = static::getFileStorage()->create([
      'uri' => $processedAudioUri,
      'uid' => 1,
      'filename' => basename($processedAudioUri),
      'filemime' => 'audio/m4a',
      'status' => TRUE,
    ])->enforceIsNew();
    $newProcessedAudioFile->save();
    $newProcessedAudio = static::getMediaStorage()->create([
      'bundle' => 'audio',
      'uid' => 1,
      'name' => $outputDisplayFilename,
      'field_audio_file' => [['target_id' => (int) $newProcessedAudioFile->id()]],
    ])->enforceIsNew();
    $newProcessedAudio->save();

    // Link the new file to this media entity.
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
    $processedAudioItem->set('target_id', $newProcessedAudio->id());

    // Set the audio duration.
    $durationField = $this->get('duration');
    if ($durationField->count() === 0) $durationField->appendItem($audioDuration);
    else $durationField->get(0)->setValue($audioDuration);

    return TRUE;
  }

  /**
   * Tells whether the audio processing was initiated by reading field value.
   */
  public function wasAudioProcessingInitiated() : bool {
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
      // have identical keys and values.
      ->setSetting('handler_settings', ['target_bundles' => ['audio' => 'audio']]);
    $fields['unprocessed_audio'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Unprocessed Audio'))
      ->setDescription(new TranslatableMarkup('Unprocessed audio file.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('target_type', 'file')
      // The module consumer may which to override some of these settings using
      // a bundle.
      ->setSetting('uri_scheme', 's3')
      ->setSetting('file_extensions', 'mp3 mp4 m4a wav aac ogg')
      ->setSetting('max_filesize', '1 GB')
      ->setSetting('file_directory', 'audio-uploads');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) : void {
    /** @var null[] */
    static $finishedEntityIds = [];
    foreach ($entities as $entity) {
      $entityId = $entity->id();

      // If 1) postLoad() hasn't been run for $entity, 2) the processed audio
      // field isn't already set, and 3) processing_initiated is set, then we
      // call refreshProcessedAudio() on the entity. Condition 1) avoids various
      // problems with the static entity cache being cleared and
      // refreshProcessedAudio() thus being called multiple times on the same
      // entity in the same request cycle (such as when it is called again when
      // the entity is saved).

      // Condition 1:
      if (array_key_exists($entityId, $finishedEntityIds)) continue;

      if (!($entity instanceof SermonAudio)) {
        throw new \InvalidArgumentException('Invalid entity type in $entities.');
      }

      // Condition 2:
      if ($entity->hasProcessedAudio()) continue;
      // Condition 3:
      if (!$entity->wasAudioProcessingInitiated()) continue;

      if ($entity->refreshProcessedAudio()) {
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
