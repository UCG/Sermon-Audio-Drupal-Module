<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Ranine\Helper\CastHelpers;

/**
 * Parses values from certain of this module's settings.
 *
 * @static
 */
final class SettingsHelpers {

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Gets the connection timeout setting if it is properly set.
   *
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig $moduleSettings
   *   Module settings.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   NULL nor casts to a positive a integer.
   */
  public static function getConnectionTimeout(Config|ImmutableConfig $moduleSettings) : ?int {
    $connectTimeout = CastHelpers::intyToNullableInt($moduleSettings->get('connect_timeout'));
    if ($connectTimeout !== NULL && $connectTimeout <= 0) {
      throw new ModuleConfigurationException('The connect_timeout module setting converts to a nonpositive integer.');
    }
    return $connectTimeout;
  }

  /**
   * Gets the HTTP AWS endpoint timeout setting if it is properly set.
   *
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig $moduleSettings
   *   Module settings.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "endpoint_timeout" configuration setting is
   *   neither NULL nor casts to a positive integer.
   */
  public static function getEndpointTimeout(Config|ImmutableConfig $moduleSettings) : ?int {
    $endpointTimeout = CastHelpers::intyToNullableInt($moduleSettings->get('endpoint_timeout'));
    if ($endpointTimeout !== NULL && $endpointTimeout <= 0) {
      throw new ModuleConfigurationException('The endpoint_timeout module setting converts to a nonpositive integer.');
    }
    return $endpointTimeout;
  }

}
