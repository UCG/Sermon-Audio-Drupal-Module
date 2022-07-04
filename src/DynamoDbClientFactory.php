<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Ranine\Helper\StringHelpers;

/**
 * Returns/creates AWS DynamoDB client objects.
 */
class DynamoDbClientFactory {

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
   * Creates a new client instance.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "aws_credentials_file_path" configuration setting points to an invalid or
   *   missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "jobs_db_aws_region" configuration setting is missing or empty.
   */
  private function createClient() : void {
    $credentialsFilePath = trim((string) $this->configuration->get('aws_credentials_file_path'));
    if ($credentialsFilePath !== '') {
      $credentialsJson = file_get_contents($credentialsFilePath);
      if (!is_string($credentialsJson)) {
        throw new ModuleConfigurationException('Failed to read from credentials file specified by aws_credentials_file_path.');
      }
      $credentialsArray = json_decode($credentialsJson, TRUE);
      if (!is_array($credentialsArray)) {
        throw new ModuleConfigurationException('File contents at aws_credentials_file_path was not valid JSON.');
      }
      if (!array_key_exists('access-key', $credentialsArray)) {
        throw new ModuleConfigurationException('Missing "access-key" setting in aws_credentials_file_path file.');
      }
      if (!array_key_exists('secret-key', $credentialsArray)) {
        throw new ModuleConfigurationException('Missing "secret-key" setting in aws_credentials_file_path file.');
      }
      $accessKey = $credentialsArray['access-key'];
      $secretKey = $credentialsArray['secret-key'];
      if (!StringHelpers::isNonEmptyString($accessKey)) {
        throw new ModuleConfigurationException('Invalid or empty "access-key" setting in aws_credentials_file_path file.');
      }
      if (!StringHelpers::isNonEmptyString($secretKey)) {
        throw new ModuleConfigurationException('Invalid or empty "secret-key" setting in aws_credentials_file_path file.');
      }

      $credentials = new Credentials($accessKey, $secretKey);
    }

    $region = (string) $this->configuration->get('jobs_db_aws_region');
    if ($region === '') {
      throw new ModuleConfigurationException('The jobs_db_aws_region setting is missing or empty.');
    }

    if (isset($credentials)) {
      $this->client = new DynamoDbClient([
        'region' => $region,
        'version' => 'latest',
        'credentials' => $credentials,
      ]);
    }
    else {
      $this->client = new DynamoDbClient([
        'region' => $region,
        'version' => 'latest',
      ]);
    }
  }

}
