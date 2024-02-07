<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureInterface;
use Aws\Signature\SignatureV4;
use Drupal\sermon_audio\HttpMethod;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ranine\Helper\ThrowHelpers;

/**
 * Helper methods dealing with AWS API calls.
 *
 * @static
 */
final class ApiHelpers {

  /**
   * Cached signatures by AWS region.
   *
   * @var \Aws\Signature\SignatureInterface[]
   */
  private static array $signaturesByRegion = [];

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Calls the given AWS API and returns the response.
   *
   * @param \Psr\Http\Client\ClientInterface $httpClient
   *   Client to use to make the call.
   * @param \Aws\Credentials\CredentialsInterface $credentials
   *   Credentials with which to sign the request.
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
   * @throws \Psr\Http\Client\ClientExceptionInterface
   *   Thrown if an error happens while processing the request.
   */
  public static function callApi(ClientInterface $httpClient,
    CredentialsInterface $credentials,
    string $endpoint,
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

    return $httpClient->sendRequest(self::signRequest($request, $credentials, $apiRegion));
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
   *
   * @throws \InvalidArgumentException
   *   Thrown if $region is empty.
   */
  public static function signRequest(RequestInterface $request, CredentialsInterface $credentials, string $region) : RequestInterface {
    ThrowHelpers::throwIfEmptyString($region, '$region');
    return static::getSignature($region)->signRequest($request, $credentials);
  }

  /**
   * Gets a signature used to sign requests to our APIs.
   *
   * @param string $region
   *   AWS region for signature.
   * @phpstan-param non-empty-string $region
   */
  private static function getSignature(string $region) : SignatureInterface {
    assert($region !== '');
    if (!isset(self::$signaturesByRegion[$region])) {
      self::$signaturesByRegion[$region] = new SignatureV4('execute-api', $region);
    }
    return self::$signaturesByRegion[$region];
  }

}
