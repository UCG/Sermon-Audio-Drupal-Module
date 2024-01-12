<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Drupal\sermon_audio\Entity\SermonAudio;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validator for SermonProcessedAudioAndDurationMatchingNullity constraint.
 */
class SermonProcessedAudioAndDurationMatchingNullityConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint) : void {
    if (!($entity instanceof SermonAudio)) {
      throw new \InvalidArgumentException('$entity is not a sermon audio entity.');
    }
    if (!($constraint instanceof SermonProcessedAudioAndDurationMatchingNullityConstraint)) {
      throw new \InvalidArgumentException('$constraint is not of the expected type.');
    }

    // We expect the processed audio duration to be set when the processed audio
    // is set, and the converse is true also.
    if ($entity->hasProcessedAudio() !== ($entity->getDuration() !== NULL)) {
      $this->context->addViolation('The duration and processed audio fields do not have matching nullity.');
    }
  }

}
