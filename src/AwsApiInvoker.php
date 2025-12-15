<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureInterface;
use Aws\Signature\SignatureV4;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\AwsCredentialsRetriever;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Drupal\sermon_audio\Helper\SettingsHelpers;
use Drupal\sermon_audio\HttpMethod;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ranine\Helper\ThrowHelpers;

/**
 * Makes calls to AWS API Gateway APIs.
 */
class AwsApiInvoker {

  /**
   * Module configuration.
   */
  private ImmutableConfig $configuration;

  private AwsCredentialsRetriever $credentialsRetriever;

  /**
   * HTTP client service.
   */
  private ClientInterface $httpClient;

  /**
   * Cached signatures by AWS region.
   *
   * @var \Aws\Signature\SignatureInterface[]
   */
  private array $signaturesByRegion = [];

  /**
   * Creates a new AWS API invoker.
   *
   * @param \Drupal\sermon_audio\AwsCredentialsRetriever $credentialsRetriever
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   */
  public function __construct(AwsCredentialsRetriever $credentialsRetriever, ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->credentialsRetriever = $credentialsRetriever;
    $this->httpClient = $httpClient;
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Calls the given AWS API and returns the response.
   *
   * @param string $endpoint
   *   HTTP endpoint to call.
   * @phpstan-param non-empty-string $endpoint
   * @param string $apiRegion
   *   AWS region of API.
   * @phpstan-param non-empty-string $apiRegion
   * @param ?array $body
   *   JSON-able body to send, or NULL not to send a body.
   * @param string[] $query
   *   Query parameters to send.
   * @param \Drupal\sermon_audio\HttpMethod $method
   *   HTTP method to use (GET or POST).
   *
   * @throws \InvalidArgumentException
   *   Thrown if $endpoint or $apiRegion is empty.
   * @throws \InvalidArgumentException
   *   Thrown if $body is non-NULL and cannot be encoded as JSON.
   * @throws \Guzzle\Exception\GuzzleException
   *   Thrown if an error happens while processing the request.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if the AWS credentials file was not specified, could not be
   *   loaded, or is invalid.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" or "endpoint_timeout"
   *   configuration setting is neither NULL nor casts to a positive a integer.
   */
  public function callApi(string $endpoint, // @phpstan-ignore-line
    string $apiRegion,
    ?array $body = NULL,
    array $query = [],
    HttpMethod $method = HttpMethod::GET) : ResponseInterface {
    // @todo Implement retry?

    ThrowHelpers::throwIfEmptyString($endpoint, '$endpoint');
    ThrowHelpers::throwIfEmptyString($apiRegion, '$apiRegion');

    $methodString = match ($method) {
      HttpMethod::GET => 'GET',
      HttpMethod::POST => 'POST',
    };
    if ($query === []) $uri = $endpoint;
    else $uri = $endpoint . '?' . http_build_query($query);
    if ($body === NULL) $encodedBody = NULL;
    else {
      $encodedBody = json_encode($body);
      if ($encodedBody === FALSE) throw new \InvalidArgumentException('Failed to encode $body as JSON.');
    }

    $request = new Request(
      $methodString,
      $uri,
      ['Content-Type' => 'application/json', 'Accept' => 'application/json; charset=utf8'],
      $encodedBody);

    $connectionTimeout = SettingsHelpers::getConnectionTimeout($this->configuration);
    $endpointTimeout = SettingsHelpers::getEndpointTimeout($this->configuration);
    $credentials = $this->credentialsRetriever->getCredentials();
    if ($credentials === NULL) {
      throw new ModuleConfigurationException('AWS credentials file is not specified.');
    }

    $requestOptions = [];
    if ($connectionTimeout !== NULL) $requestOptions['connect_timeout'] = $connectionTimeout;
    if ($endpointTimeout !== NULL) $requestOptions['timeout'] = $endpointTimeout;
    return $this->httpClient->send(self::signRequest($request, $credentials, $apiRegion), $requestOptions);
  }

  /**
   * Gets a signature used to sign requests to our APIs.
   *
   * @param string $region
   *   AWS region for signature.
   * @phpstan-param non-empty-string $region
   */
  private function getSignature(string $region) : SignatureInterface {
    assert($region !== '');
    if (!isset($this->signaturesByRegion[$region])) {
      $this->signaturesByRegion[$region] = new SignatureV4('execute-api', $region);
    }
    return $this->signaturesByRegion[$region];
  }

  /**
   * Signs the given request to an AWS API Gateway API.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   Request to sign.
   * @param \Aws\Credentials\CredentialsInterface $credentials
   *   Credentials with which to sign the request.
   * @param string $region
   *   AWS region of the API.
   * @phpstan-param non-empty-string $region
   */
  private function signRequest(RequestInterface $request, CredentialsInterface $credentials, string $region) : RequestInterface {
    assert($region !== '');
    return $this->getSignature($region)->signRequest($request, $credentials);
  }

}
