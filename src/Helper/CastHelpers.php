<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

/**
 * Helper methods dealing with casting.
 *
 * @static
 */
final class CastHelpers {

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Converts a scalar value (or \Stringable) to a string.
   *
   * Asserts that $stringy is a scalar or an instance of \Stringable, and then
   * casts it. This method is partly used for getting rid of the PHPStan error
   * that occurs when one attempts to cast "mixed" to "string."
   *
   * @param mixed $stringy
   *   String (or stringable) to convert.
   *
   * @return string
   *   Casted string.
   */
  public static function stringyToString($stringy) : string {
    assert(is_scalar($stringy) || $stringy instanceof \Stringable);
    return (string) $stringy;
  }

}
