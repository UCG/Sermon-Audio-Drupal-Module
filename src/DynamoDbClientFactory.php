<?php

declare (strict_types = 1);

namespace Drupal\processed_audio_entity;

use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\processed_audio_entity\Exception\ModuleConfigurationException;

/**
 * Returns/creates AWS DynamoDB client objects.
 */
class DynamoDbClientFactory {

  /**
   * DynamoDB client instance.
   */
  private DynamoDbClient $client;

  /**
   * Creates a new DynamoDB client factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   * @param \Aws\Credentials\Credentials|null $credentials
   *   AWS credentials to use when initializing client, or NULL to use the
   *   default credential discovery method.
   *
   * @throws \Drupal\processed_audio_entity\Exception\ModuleConfigurationException
   *   Thrown if the module's "jobs_db_aws_region" configuration setting is
   *   missing or empty.
   */
  public function __construct(ConfigFactoryInterface $configFactory, ?Credentials $credentials = NULL) {
    $region = (string) $configFactory->get('processed_audio_entity.settings')->get('jobs_db_aws_region');
    if ($region === '') {
      throw new ModuleConfigurationException('The jobs_db_aws_region settings is missing or empty.');
    }

    if ($credentials === NULL) {
      $this->client = new DynamoDbClient([
        'region' => $region,
        'version' => 'latest',
      ]);
    }
    else {
      $this->client = new DynamoDbClient([
        'region' => $region,
        'version' => 'latest',
        'credentials' => $credentials,
      ]);
    }
  }

  /**
   * Gets an AWS DynamoDB client.
   *
   * May create a new instance, or re-use an existing instance.
   */
  public function getClient() : DynamoDbClient {
    return $this->client;
  }

}
