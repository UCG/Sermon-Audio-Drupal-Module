<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;

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
   *   Thrown the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  public static function getConnectionTimeout(Config|ImmutableConfig $moduleSettings) : ?int {
    $connectTimeout = $moduleSettings->get('connect_timeout');
    if (empty($connectTimeout)) return NULL;
    else {
      $connectTimeout = (int) $connectTimeout;
      if ($connectTimeout <= 0) {
        throw new ModuleConfigurationException('The connect_timeout module setting converts to a nonpositive integer.');
      }
      return $connectTimeout;
    }
  }

  /**
   * Gets the DynamoDB timeout setting if it is properly set.
   *
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig $moduleSettings
   *   Module settings.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown the module's "dynamodb_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  public static function getDynamoDbTimeout(Config|ImmutableConfig $moduleSettings) : ?int {
    $timeout = $moduleSettings->get('dynamodb_timeout');
    if (empty($timeout)) return NULL;
    else {
      $timeout = (int) $timeout;
      if ($timeout <= 0) {
        throw new ModuleConfigurationException('The dynamodb_timeout module setting converts to a nonpositive integer.');
      }
      return $timeout;
    }
  }

}
