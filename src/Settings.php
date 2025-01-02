<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Config\ImmutableConfig;
use Ranine\Helper\CastHelpers;

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
   * Gets the path to the AWS credentials file.
   */
  public static function getAwsCredentialsFilePath() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('aws_credentials_file_path'));
  }

  /**
   * Gets the audio cleaning job results AWS API region.
   */
  public static function getCleaningJobResultsApiRegion() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('cleaning_job_results_endpoint_aws_region'));
  }

  /**
   * Gets the audio cleaning job results AWS API HTTP endpoint.
   */
  public static function getCleaningJobResultsApiEndpoint() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('cleaning_job_results_endpoint'));
  }

  /**
   * Gets the AWS HTTP connection timeout in seconds, if set.
   */
  public static function getConnectionTimeout() : ?int {
    return CastHelpers::intyToNullableInt(static::getSettings()->get('connect_timeout'));
  }

  /**
   * Gets the AWS HTTP API endpoint timeout in seconds, if set.
   */
  public static function getEndpointTimeout() : ?int {
    return CastHelpers::intyToNullableInt(static::getSettings()->get('endpoint_timeout'));
  }

  /**
   * Gets the audio processing job submission AWS API region.
   */
  public static function getJobSubmissionApiRegion() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('job_submission_endpoint_aws_region'));
  }

  /**
   * Gets the audio processing job submission AWS API HTTP endpoint.
   */
  public static function getJobSubmissionApiEndpoint() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('job_submission_endpoint'));
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
   * Gets the name of the S3 transcription XML file bucket.
   */
  public static function getTranscriptionBucketName() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_bucket_name'));
  }

  /**
   * Gets the audio transcription job results AWS API region.
   */
  public static function getTranscriptionJobResultsApiRegion() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_job_results_endpoint_aws_region'));
  }

  /**
   * Gets the audio transcription job results AWS API HTTP endpoint.
   */
  public static function getTranscriptionJobResultsApiEndpoint() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_job_results_endpoint'));
  }

  /**
   * Gets the transcription-only job submission AWS API region.
   */
  public static function getTranscriptionJobSubmissionApiRegion() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_job_submission_endpoint_aws_region'));
  }

  /**
   * Gets the transcription-only job submission endpoint.
   */
  public static function getTranscriptionJobSubmissionApiEndpoint() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_job_submission_endpoint'));
  }

  /**
   * Gets the S3 key prefix for transcription XML files.
   */
  public static function getTranscriptionKeyPrefix() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_key_prefix'));
  }

  /**
   * Gets the transcription XML storage AWS S3 region.
   */
  public static function getTranscriptionS3Region() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('transcription_s3_aws_region'));
  }

  /**
   * Gets the unprocessed audio file URI prefix.
   */
  public static function getUnprocessedAudioUriPrefix() : string {
    return CastHelpers::stringyToString(static::getSettings()->get('unprocessed_audio_uri_prefix'));
  }

  /**
   * Tells whether debug mode is enabled.
   */
  public static function isDebugModeEnabled() : bool {
    return (bool) static::getSettings()->get('debug_mode');
  }

  /**
   * Gets the module settings object.
   */
  private static function getSettings() : ImmutableConfig {
    return \Drupal::config('sermon_audio.settings');
  }

}
