<?php

declare (strict_types = 1);

namespace Drupal\processed_audio_entity;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Allows read-access to this module's settings.
 *
 * Rather than using this static class, it is preferable to use dependency
 * injection if available.
 *
 * @static
 */
final class Settings {

  /**
   * Module settings.
   */
  private ImmutableConfig $settings;

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Gets the audio processing jobs AWS region setting.
   */
  public static function getJobsDbAwsRegion() : string {
    return (string) static::getSettings()->get('jobs_db_aws_region');
  }

  /**
   * Gets the audio processing jobs table name.
   */
  public static function getJobsTableName() : string {
    return (string) static::getSettings()->get('jobs_table_name');
  }
  
    /**
     * Gets the required processed audio file URI prefix.
     */
    public static function getProcessedAudioUriPrefix() : string {
      return (string) static::getSettings()->get('processed_audio_uri_prefix');
    }

  /**
   * Gets the required unprocessed audio file URI prefix.
   */
  public static function getUnprocessedAudioUriPrefix() : string {
    return (string) static::getSettings()->get('unprocessed_audio_uri_prefix');
  }

  /**
   * Gets the module settings object.
   */
  private static function getSettings() : ImmutableConfig {
    if (!isset(static::$settings)) {
      static::$settings = \Drupal::config('processed_audio_entity.settings');
    }
    return static::$settings;
  }

}
