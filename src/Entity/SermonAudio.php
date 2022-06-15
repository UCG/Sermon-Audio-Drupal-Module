<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Entity;

use Drupal\Core\Entity\ContentEntityBase;

/**
 * An entity representing audio for a sermon.
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
