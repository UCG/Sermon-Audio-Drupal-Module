<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures sermon_audio processed_audio & duration fields have matching nullity.
 *
 * These fields can either be both NULL or both not NULL.
 *
 * @Constraint(
 *   id = "SermonProcessedAudioAndDurationMatchingNullity",
 *   label = @Translation("Sermon Processed Audio and Duration Fields Matching Nullity", context = "Validation"),
 *   type = "entity"
 * )
 */
class SermonProcessedAudioAndDurationMatchingNullityConstraint extends Constraint {
}
