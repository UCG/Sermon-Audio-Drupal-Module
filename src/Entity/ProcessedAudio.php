<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\file\FileInterface;
use Drupal\file\FileStorageInterface;
use Drupal\media\Entity\Media;
use Drupal\sermon_audio\DynamoDbClientFactory;
use Drupal\sermon_audio\Exception\EntityValidationException;
use Drupal\sermon_audio\Exception\InvalidInputAudioFileException;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Settings;
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
 * with the processed audio file, and the entity is saved. The AWS audio
 * processing job check and subsequent processed audio field update can also be
 * forced by calling refreshProcessedAudio().
 */
class ProcessedAudio extends Media {

  

  /**
   * Attempts to correctly set the processed audio field.
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
   *   Thrown if an error occurs when trying to save a new file entity.
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
    $outputPrefix = Settings::getProcessedAudioUriPrefix();
    $processedAudioUri = $outputPrefix . $outputSubKey;
    // Before creating the file entity, check to see if the current processed
    // audio entity already has the correct URI.
    if ($this->getProcessedAudioFile()?->get('uri')->get(0)?->getValue() === $processedAudioUri) {
      return FALSE;
    }

    $newProcessedAudio = static::getFileStorage()->create([
      'uri' => $processedAudioUri,
      'uid' => 1,
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
    assert($processedAudioItem instanceof FieldItemBase);
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
      $entityId = $entity->id();

      // If 1) postLoad() hasn't been run for $entity, 2) the processed audio
      // field isn't already set, and 3) field_audio_processing_initiated is
      // set, then we call refreshProcessedAudio() on the entity. Condition 1)
      // avoids various problems with the static entity cache being cleared and
      // refreshProcessedAudio() being called multiple times on the same entity
      // in the same request cycle (such as when it is called again when the
      // entity is saved).

      // Condition 1:
      if (array_key_exists($entityId, $finishedEntityIds)) continue;

      if (!($entity instanceof ProcessedAudio)) {
        throw new \InvalidArgumentException('Invalid entity type in $entities.');
      }

      // Condition 2:
      if ($entity->getProcessedAudioFid() !== NULL) continue;
      // Condition 3:
      if (!$entity->get('field_audio_processing_initiated')->get(0)?->getValue()) continue;

      
      if ($entity->refreshProcessedAudio()) {
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

}
