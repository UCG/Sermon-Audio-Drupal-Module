<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\Credentials\Credentials;
use Ranine\Helper\StringHelpers;

/**
 * Returns/creates a client object to interact with an AWS service.
 */
abstract class AwsClientFactoryBase {

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
   * @throws \Exception
   *   Thrown if something is wrong with the credentails (see the various
   *   static::throw*() methods).
   */
  protected static function getCredentials(string $credentialsFilePath) : Credentials {
    assert($credentialsFilePath !== '');

    $credentialsJson = file_get_contents($credentialsFilePath);
    if (!is_string($credentialsJson)) {
      static::throwFailedToReadCredsFileException();
    }
    $credentialsArray = json_decode($credentialsJson, TRUE);
    if (!is_array($credentialsArray)) {
      static::throwCredsFileInvalidJsonException();
    }
    if (!array_key_exists('access-key', $credentialsArray)) {
      static::throwMissingAccessKeyException();
    }
    if (!array_key_exists('secret-key', $credentialsArray)) {
      static::throwMissingSecretKeyException();
    }
    $accessKey = $credentialsArray['access-key'];
    $secretKey = $credentialsArray['secret-key'];
    if (!StringHelpers::isNonEmptyString($accessKey)) {
      static::throwInvalidAccessKeyException();
    }
    if (!StringHelpers::isNonEmptyString($secretKey)) {
      static::throwInvalidSecretKeyException();
    }

    return new Credentials($accessKey, $secretKey);
  }

  /**
   * Throws an exception for when the credentials JSON file is invalid.
   *
   * @throws \Exception
   */
  protected static abstract function throwCredsFileInvalidJsonException() : never;

  /**
   * Throws an exception for when the credentials JSON file cannot be read.
   *
   * @throws \Exception
   */
  protected static abstract function throwFailedToReadCredsFileException() : never;

  /**
   * Throws an exception for when the access key is invalid or empty.
   *
   * @throws \Exception
   */
  protected static abstract function throwInvalidAccessKeyException() : never;

  /**
   * Throws an exception for when the secret key is invalid or empty.
   *
   * @throws \Exception
   */
  protected static abstract function throwInvalidSecretKeyException() : never;

  /**
   * Throws an exception for when the access key is missing in the JSON file.
   *
   * @throws \Exception
   */
  protected static abstract function throwMissingAccessKeyException() : never;

  /**
   * Throws an exception for when the secret key is missing in the JSON file
   *
   * @throws \Exception
   */
  protected static abstract function throwMissingSecretKeyException() : never;

}
