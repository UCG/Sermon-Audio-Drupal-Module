<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\Credentials\Credentials;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\CastHelpers;

/**
 * Obtains AWS credentials.
 */
class AwsCredentialsRetriever {

  /**
   * Cached AWS credentials.
   */
  private ?Credentials $credentials = NULL;

  /**
   * Module configuration.
   */
  private ImmutableConfig $configuration;

  /**
   * Creates a new AWS credentials retriever.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Gets the AWS credentials, or NULL if no credentials file is set.
   *
   * May create a new credentials instance, or re-use an existing instance. If
   * the "aws_credentials_file_path" is empty or whitespace, NULL is returned if
   * there is no existing credentials instance. If "aws_credentials_file_path"
   * is neither empty nor whitespace (and there is no existing credentials
   * instance) a new instance is created from the JSON file at that location.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if there is no existing credentials instance, and the module's
   *   "aws_credentials_file_path" configuration setting is not empty and not
   *   whitespace yet points to an invalid or missing credentials file.
   */
  public function getCredentials() : ?Credentials {
    if (!isset($this->credentials)) {
      $credentialsFilePath = trim(CastHelpers::stringyToString($this->configuration->get('aws_credentials_file_path')));
      if ($credentialsFilePath !== '') $this->credentials = static::getCredentialsFromFile($credentialsFilePath);
      // The configuration won't be needed anymore.
      unset($this->configuration);
    }
    return $this->credentials;
  }

  /**
   * Retrieves and returns credentials from given JSON file.
   *
   * The JSON file should consist of an object with two keys, "access-key" and
   * "secret-key", whose corresponding values are the plaintext AWS account
   * access key and secret key, respectively.
   *
   * @param string $credentialsFilePath
   *   Path to JSON file.
   * @phpstan-param non-empty-string $credentialsFilePath
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if something is wrong with the credentials or if something goes
   *   wrong when trying to read from the credentials file.
   */
  private static function getCredentialsFromFile(string $credentialsFilePath) : Credentials {
    assert($credentialsFilePath !== '');

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

    return new Credentials($accessKey, $secretKey);
  }

}
