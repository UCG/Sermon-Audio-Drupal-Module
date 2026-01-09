<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;
use Symfony\Component\Validator\Constraint;

/**
 * Ensures sermon_audio processed_audio & duration fields have matching nullity.
 *
 * These fields can either be both NULL or both not NULL.
 */
#[ConstraintAttribute(id: 'SermonProcessedAudioAndDurationMatchingNullity',
  label: new TranslatableMarkup('Sermon Processed Audio and Duration Fields Matching Nullity', options: ['context' => 'Validation']),
  type: 'entity',
)]
class SermonProcessedAudioAndDurationMatchingNullityConstraint extends Constraint {
}
