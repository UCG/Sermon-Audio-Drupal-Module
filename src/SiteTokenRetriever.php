<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\CastHelpers;

/**
 * Obtains the site token (controlling access to the announcement routes).
 */
class SiteTokenRetriever {

  /**
   * Module configuration.
   */
  private ImmutableConfig $configuration;

  /**
   * Cached site token.
   */
  private ?string $token = NULL;

  /**
   * Creates a new AWS credentials retriever.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Gets the site token.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the token file location is empty or unset in the module
   *   configuration.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the token file is empty or whitespace, or could not be read.
   */
  public function getToken() : string {
    if (!isset($this->token)) {
      $tokenFilePath = trim(CastHelpers::stringyToString($this->configuration->get('site_token_file_path')));
      if ($tokenFilePath === '') {
        throw new ModuleConfigurationException('Module "site_token_file_path" setting is whitespace or unset.');
      }
      $this->token = self::getTokenFromFile($tokenFilePath);
      // The configuration won't be needed anymore.
      unset($this->configuration);
    }
    return $this->token;
  }

  /**
   * Retrieves and returns site token from given text file.
   *
   * The contents of the file is read and trimmed, and that is presumed to be
   * the token.
   *
   * @param string $tokenFilePath
   *   Path to token file.
   * @phpstan-param non-empty-string $tokenFilePath
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the token file is empty or whitespace, or could not be read.
   */
  private static function getTokenFromFile(string $tokenFilePath) : string {
    assert($tokenFilePath !== '');

    $token = file_get_contents($tokenFilePath);
    if (!is_string($token)) {
      throw new ModuleConfigurationException('Failed to read from token file specified by site_token_file_path.');
    }
    $token = trim($token);
    if ($token === '') {
      throw new ModuleConfigurationException('Token file specified by site_token_file_path is empty or whitespace.');
    }

    return $token;
  }

}
