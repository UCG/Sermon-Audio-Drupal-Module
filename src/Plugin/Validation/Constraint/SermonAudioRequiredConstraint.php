<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures processed_audio & unprocessed_audio fields are not both NULL.
 *
 * Designed for sermon_audio entities.
 *
 * @Constraint(
 *   id = "SermonAudioRequired",
 *   label = @Translation("Sermon Processed or Unprocessed Audio Required", context = "Validation"),
 *   type = "entity"
 * )
 */
class SermonAudioRequiredConstraint extends Constraint {
}
