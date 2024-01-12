<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Validation\Constraint;

use Drupal\sermon_audio\Entity\SermonAudio;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validator for SermonAudioRequired constraint.
 */
class SermonAudioRequiredConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint) : void {
    if (!($entity instanceof SermonAudio)) {
      throw new \InvalidArgumentException('$entity is not a sermon audio entity.');
    }
    if (!($constraint instanceof SermonAudioRequiredConstraint)) {
      throw new \InvalidArgumentException('$constraint is not of the expected type.');
    }

    if (!$entity->hasProcessedAudio() && !$entity->hasUnprocessedAudio()) {
      $this->context->addViolation('The processed and unprocessed audio fields are both empty.');
    }
  }

}
