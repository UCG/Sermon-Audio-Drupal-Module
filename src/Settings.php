<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Helper\CastHelpers;

/**
 * Allows read-only access to this module's settings.
 *
 * Rather than using this static class, it is preferable to use dependency
 * injection if available.
 *
 * @static
 */
final class Settings {

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Gets the name of the S3 audio bucket.
   */
  public static function getAudioBucketName() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('audio_bucket_name'));
  }

  /**
   * Gets the audio storage AWS S3 region.
   */
  public static function getAudioS3Region() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('audio_s3_aws_region'));
  }

  /**
   * Gets the audio processing jobs AWS region setting.
   */
  public static function getJobsDbAwsRegion() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('jobs_db_aws_region'));
  }

  /**
   * Gets the audio processing jobs table name.
   */
  public static function getJobsTableName() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('jobs_table_name'));
  }

  /**
   * Gets the S3 key prefix for processed audio.
   */
  public static function getProcessedAudioKeyPrefix() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('processed_audio_key_prefix'));
  }

    /**
     * Gets the processed audio file URI prefix.
     */
    public static function getProcessedAudioUriPrefix() : string {
      return CastHelpers::stringyToString(static::getSettings()->get('processed_audio_uri_prefix'));
    }

  /**
   * Gets the unprocessed audio file URI prefix.
   */
  public static function getUnprocessedAudioUriPrefix() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('unprocessed_audio_uri_prefix'));
  }

  /**
   * Gets the module settings object.
   */
  private static function getSettings() : ImmutableConfig {
    return \Drupal::config('sermon_audio.settings');
  }

}
