<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\S3\S3Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\CastHelpers;
use Drupal\sermon_audio\Helper\SettingsHelpers;

/**
 * Returns/creates AWS S3 client objects.
 */
class S3ClientFactory extends AwsClientFactoryBase {

  /**
   * S3 client instance.
   */
  private S3Client $client;

  /**
   * Module configuration.
   */
  private readonly ImmutableConfig $configuration;

  /**
   * Creates a new S3 client factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Gets an AWS S3 client.
   *
   * May create a new instance, or re-use an existing instance. When a new
   * instance is created, note that if the "aws_credentials_file_path" setting
   * is not empty or whitespace, the JSON file at that location is used to
   * obtain AWS credentials. Otherwise, the credentials are obtained using AWS's
   * default procedure.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "aws_credentials_file_path" configuration setting points to an invalid or
   *   missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "audio_s3_aws_region" configuration setting is missing or empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  public function getClient() : S3Client {
    if (!isset($this->client)) {
      $this->createClient();
      assert(isset($this->client));
      // The configuration won't be needed anymore.
      unset($this->configuration);
    }
    return $this->client;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwCredsFileInvalidJsonException() : never {
    throw new ModuleConfigurationException('File contents at aws_credentials_file_path was not valid JSON.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwFailedToReadCredsFileException() : never {
    throw new ModuleConfigurationException('Failed to read from credentials file specified by aws_credentials_file_path.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwInvalidAccessKeyException() : never {
    throw new ModuleConfigurationException('Invalid or empty "access-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwInvalidSecretKeyException() : never {
    throw new ModuleConfigurationException('Invalid or empty "secret-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwMissingAccessKeyException() : never {
    throw new ModuleConfigurationException('Missing "access-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwMissingSecretKeyException() : never {
    throw new ModuleConfigurationException('Missing "secret-key" setting in aws_credentials_file_path file.');
  }

  /**
   * Creates a new client instance.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "aws_credentials_file_path" configuration setting points to an invalid or
   *   missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "audio_s3_aws_region" configuration setting is missing or empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  private function createClient() : void {
    $credentialsFilePath = trim(CastHelpers::stringyToString($this->configuration->get('aws_credentials_file_path')));
    if ($credentialsFilePath !== '') {
      $credentials = static::getCredentials($credentialsFilePath);
    }

    $region = CastHelpers::stringyToString($this->configuration->get('audio_s3_aws_region'));
    if ($region === '') {
      throw new ModuleConfigurationException('The audio_s3_aws_region setting is missing or empty.');
    }

    $connectTimeout = SettingsHelpers::getConnectionTimeout($this->configuration);

    $connectionOptions = [
      'region' => $region,
      'version' => 'latest',
    ];
    if ($connectTimeout !== NULL) {
      $connectionOptions['http'] = ['connect_timeout' => $connectTimeout];
    }
    if (isset($credentials)) $connectionOptions['credentials'] = $credentials;

    $this->client = new S3Client($connectionOptions);
  }

}
