<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Drupal\Core\Entity\ContentEntityBase;

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
}
