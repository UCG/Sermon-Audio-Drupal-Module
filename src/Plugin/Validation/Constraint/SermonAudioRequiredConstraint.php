<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;
use Symfony\Component\Validator\Constraint;

/**
 * Ensures processed_audio & unprocessed_audio fields are not both NULL.
 *
 * Designed for sermon_audio entities.
 */
#[ConstraintAttribute(id: 'SermonAudioRequired',
  label: new TranslatableMarkup('Sermon Processed or Unprocessed Audio Required', options: ['context' => 'Validation']),
  type: 'entity',
)]
class SermonAudioRequiredConstraint extends Constraint {
}
