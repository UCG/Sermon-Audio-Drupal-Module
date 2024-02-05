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
   * Converts a scalar or NULL value to an integer.
   *
   * Asserts that $inty is a scalar or equal to NULL, and then casts it. This
   * method is partly used for getting rid of the PHPStan error that occurs when
   * one attempts to cast "mixed" to "int."
   */
  public static function intyToInt(mixed $inty) : int {
    assert(is_scalar($inty) || $inty === NULL);
    return (int) $inty;
  }

  /**
   * Converts a scalar or NULL value to a nullable integer.
   *
   * If $inty is NULL, returns NULL. Else, asserts that $inty is a scalar, and
   * then casts it. This method is partly used for getting rid of the PHPStan
   * error that occurs when one attempts to cast "mixed" to "int."
   */
  public static function intyToNullableInt(mixed $inty) : ?int {
    if ($inty === NULL) return NULL;
    else {
      assert(is_scalar($inty));
      return (int) $inty;
    }
  }

  /**
   * Converts a scalar, NULL, or \Stringable value to a ?string.
   *
   * If $stringy is NULL, returns NULL. Else, asserts that $stringy is a scalar
   * or an instance of \Stringable, and then returns the casted string.
   * This method is partly used for getting rid of the PHPStan error that occurs
   * when one attempts to cast "mixed" to "string."
   */
  public static function stringyToNullableString(mixed $stringy) : ?string {
    if ($stringy === NULL) return NULL;
    else {
      assert(is_scalar($stringy) || $stringy instanceof \Stringable);
      return (string) $stringy;
    }
  }

  /**
   * Converts a scalar, NULL, or \Stringable value to a string.
   *
   * Asserts that $stringy is a scalar, equal to NULL, or an instance of
   * \Stringable, and then casts it. This method is partly used for getting rid
   * of the PHPStan error that occurs when one attempts to cast "mixed" to
   * "string."
   */
  public static function stringyToString(mixed $stringy) : string {
    assert(is_scalar($stringy) || $stringy instanceof \Stringable || $stringy === NULL);
    return (string) $stringy;
  }

}
