<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

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
 * )
 */
class SermonAudio extends ContentEntityBase {

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

}
