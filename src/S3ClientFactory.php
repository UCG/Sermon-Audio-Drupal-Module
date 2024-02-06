<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\S3\S3Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Helper\SettingsHelpers;
use Ranine\Helper\ThrowHelpers;

/**
 * Returns/creates AWS S3 client objects.
 */
class S3ClientFactory {

  /**
   * Cached S3 client instances, indexed by region.
   *
   * @var \Aws\S3\S3Client[]
   */
  private array $clients = [];

  /**
   * Module configuration.
   */
  private ImmutableConfig $configuration;

  private AwsCredentialsRetriever $credentialsRetriever;

  /**
   * Creates a new S3 client factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   * @param \Drupal\sermon_audio\AwsCredentialsRetriever $credentialsRetriever
   *   AWS credentials retriever.
   */
  public function __construct(ConfigFactoryInterface $configFactory, AwsCredentialsRetriever $credentialsRetriever) {
    $this->credentialsRetriever = $credentialsRetriever;
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
   * @throws \InvalidArgumentException
   *   Thrown if $region is empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "aws_credentials_file_path" configuration setting is not empty and not
   *   whitespace yet points to an invalid or missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  public function getClient(string $region) : S3Client {
    ThrowHelpers::throwIfEmptyString($region, 'region');

    if (!isset($this->clients[$region])) {
      $this->createClient($region);
      assert(isset($this->clients[$region]));
    }
    return $this->clients[$region];
  }

  /**
   * Creates a new client instance for the given region.
   *
   * @param string $region
   *   Region for which to create client.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if a new client instance is needed, and the module's
   *   "aws_credentials_file_path" configuration setting is not empty and not
   *   whitespace yet points to an invalid or missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   */
  private function createClient(string $region) : void {
    assert($region !== '');

    $credentials = $this->credentialsRetriever->getCredentials();
    $connectTimeout = SettingsHelpers::getConnectionTimeout($this->configuration);

    $connectionOptions = [
      'region' => $region,
      'version' => 'latest',
    ];
    if ($connectTimeout !== NULL) {
      $connectionOptions['http'] = ['connect_timeout' => $connectTimeout];
    }
    if (isset($credentials)) $connectionOptions['credentials'] = $credentials;

    $this->clients[$region] = new S3Client($connectionOptions);
  }

}
