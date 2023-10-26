<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\DynamoDb\DynamoDbClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\SettingsHelper;

/**
 * Returns/creates AWS DynamoDB client objects.
 */
class DynamoDbClientFactory extends AwsClientFactoryBase {

  /**
   * DynamoDB client instance.
   */
  private DynamoDbClient $client;

  /**
   * Module configuration.
   */
  private ImmutableConfig $configuration;

  /**
   * Creates a new DynamoDB client factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Gets an AWS DynamoDB client.
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
   *   "jobs_db_aws_region" configuration setting is missing or empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" or "dynamodb_timeout"
   *   configuration setting is neither empty nor castable to a positive
   *   integer.
   */
  public function getClient() : DynamoDbClient {
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
  protected static function throwCredsFileInvalidJsonException(): void {
    throw new ModuleConfigurationException('File contents at aws_credentials_file_path was not valid JSON.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwFailedToReadCredsFileException(): void {
    throw new ModuleConfigurationException('Failed to read from credentials file specified by aws_credentials_file_path.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwInvalidAccessKeyException(): void {
    throw new ModuleConfigurationException('Invalid or empty "access-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwInvalidSecretKeyException(): void {
    throw new ModuleConfigurationException('Invalid or empty "secret-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwMissingAccessKeyException(): void {
    throw new ModuleConfigurationException('Missing "access-key" setting in aws_credentials_file_path file.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   */
  protected static function throwMissingSecretKeyException(): void {
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
   *   "jobs_db_aws_region" configuration setting is missing or empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" or "dynamodb_timeout"
   *   configuration setting is neither empty nor castable to a positive
   *   integer.
   */
  private function createClient() : void {
    $credentialsFilePath = trim((string) $this->configuration->get('aws_credentials_file_path'));
    if ($credentialsFilePath !== '') {
      $credentials = static::getCredentials($credentialsFilePath);
    }

    $region = (string) $this->configuration->get('jobs_db_aws_region');
    if ($region === '') {
      throw new ModuleConfigurationException('The jobs_db_aws_region setting is missing or empty.');
    }

    $connectTimeout = SettingsHelper::getConnectionTimeout($this->configuration);
    $timeout = SettingsHelper::getDynamoDbTimeout($this->configuration);

    $connectionOptions = [
      'region' => $region,
      'version' => 'latest',
    ];
    if ($connectTimeout !== NULL) {
      $connectionOptions['http']['connect_timeout'] = $connectTimeout;
    }
    if ($timeout !== NULL) {
      $connectionOptions['http']['timeout'] = $timeout;
    }
    if (isset($credentials)) $connectionOptions['credentials'] = $credentials;

    $this->client = new S3Client($connectionOptions);
  }

}
