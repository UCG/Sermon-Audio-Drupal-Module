<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Exception;

use Ranine\Helper\StringHelpers;

/**
 * Indicates that a sermon audio file entity is invalid in some way.
 */
class InvalidSermonAudioFileException extends \RuntimeException {

  /**
   * Creates a new InvalidSermonAudioFileException object.
   *
   * @param ?string $message
   *   Message pertaining to exception; can be NULL or an empty string, in which
   *   case a default message is used.
   * @param int $code
   *   Exception code.
   * @param ?\Throwable $previous
   *   Previous exception/error which triggered this exception. Can be NULL to
   *   indicate no such error.
   */
  public function __construct(?string $message = NULL, int $code = 0, ?\Throwable $previous = NULL) {
    // Call the parent constructor with the message (either $message, or, if
    // $message is unset or empty [i.e., an empty string when coerced to a
    // string], a default message) and other parameters.
    parent::__construct(StringHelpers::getValueOrDefault($message, 'The sermon audio file entity is invalid.'), $code, $previous);
  }

}
