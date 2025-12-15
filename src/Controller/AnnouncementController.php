<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sermon_audio\EventSubscriber\FinishedJobProcessor;
use Drupal\sermon_audio\SiteTokenRetriever;
use Ranine\Exception\InvalidOperationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles announcements for newly cleaned/transcribed audio.
 */
class AnnouncementController extends ControllerBase {

  /**
   * Queues trans./audio refreshes for execution after response is sent.
   */
  private readonly FinishedJobProcessor $finishedJobProcessor;

  private readonly RequestStack $requestStack;

  /**
   * Retriever for route access token.
   */
  private readonly SiteTokenRetriever $tokenRetriever;

  /**
   * Creates a new announcement controller.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   * @param \Drupal\sermon_audio\SiteTokenRetriever $tokenRetriever
   *   Retriever for route access token.
   * @param \Drupal\sermon_audio\EventSubscriber\FinishedJobProcessor $finishedJobProcessor
   *   Queues transcription/audio refreshes for execution after response is
   *   sent.
   */
  public function __construct(RequestStack $requestStack, SiteTokenRetriever $tokenRetriever, FinishedJobProcessor $finishedJobProcessor) {
    $this->requestStack = $requestStack;
    $this->tokenRetriever = $tokenRetriever;
    $this->finishedJobProcessor = $finishedJobProcessor;
  }

  /**
   * Handles the endpoint for announcing newly cleaning sermon audio.
   *
   * The request should be JSON of the form {"id":"[job ID string]"}. If the
   * request was valid, this method an empty 200 response. If the Content-Type
   * of the request is invalid, a 415 response is returned. If the request is
   * malformed, a 400 response may be retunred. Finally, a 401 response is
   * returned if the request lacks valid credentials.
   *
   * The audio is not actually refreshed here -- this is done after the response
   * is sent by hooking into KernelEvents::TERMINATE.
   *
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if there is no current request on the request stack.
   */
  public function announceCleanAudio() : Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      throw new InvalidOperationException('No current request on the request stack.');
    }

    $jobId = '';
    $errorResponse = $this->tryGetJobIdFromRequest($request, $jobId);
    if ($errorResponse !== NULL) return $errorResponse;
    $this->finishedJobProcessor->setJobAsCleaningJob($jobId);
    return new Response('', 200);
  }

  /**
   * Handles the endpoint for announcing a new sermon transcription.
   *
   * The request should be JSON of the form {"id":"[job ID string]"}. If the
   * request was valid, this method an empty 200 response. If the Content-Type
   * of the request is invalid, a 415 response is returned. If the request is
   * malformed, a 400 response may be retunred. Finally, a 401 response is
   * returned if the request lacks valid credentials.
   *
   * The transcript is not actually refreshed here -- this is done after the
   * response is sent by hooking into KernelEvents::TERMINATE.
   *
   * @throws \Ranine\Exception\InvalidOperationException
   *   Thrown if there is no current request on the request stack.
   */
  public function announceNewTranscription() : Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      throw new InvalidOperationException('No current request on the request stack.');
    }

    $jobId = '';
    $errorResponse = $this->tryGetJobIdFromRequest($request, $jobId);
    if ($errorResponse !== NULL) return $errorResponse;
    $this->finishedJobProcessor->setJobAsTranscriptionJob($jobId);
    return new Response('', 200);
  }

  /**
   * Checks given request's authorization information.
   *
   * @return ?\Symfony\Component\HttpFoundation\Response
   *   NULL if the authorization check passed, or an error response (401 or 400)
   *   if it didn't.
   */
  private function checkAuthorization(Request $request) : ?Response {
    if (!$request->headers->has('Authorization')) {
      return self::getUnauthorizedResponse();
    }
    $authorizationInfo = (string) $request->headers->get('Authorization');
    if ($authorizationInfo === '') {
      return self::getAuthorizationMalformedResponse();
    }

    // To mitigate against DOS attacks, we check the length of the header before
    // processing it.
    if (strlen($authorizationInfo) >= 1024) {
      return self::getAuthorizationMalformedResponse();
    }

    $authorizationInfo = trim($authorizationInfo);
    if (!str_starts_with($authorizationInfo, 'Bearer ')) {
      return self::getUnauthorizedResponse();
    }

    $tokenB64 = substr($authorizationInfo, 7);
    $token = base64_decode($tokenB64, TRUE);
    if (!is_string($token)) {
      return self::getAuthorizationMalformedResponse();
    }

    if ($token === $this->tokenRetriever->getToken()) return NULL;
    else return self::getUnauthorizedResponse();
  }

  /**
   * Tries to get the job ID from the given request.
   *
   * Checks to see if the request is authorized and valid first. If not, returns
   * an error response. Otherwise, returns NULL and sets $jobId to the extracted
   * job ID.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request from which to get job ID.
   * @param string $jobId
   *   (output) Job ID. Not set if request was invalid.
   *
   * @return ?\Symfony\Component\HttpFoundation\Response
   *   Error response, if applicable.
   */
  private function tryGetJobIdFromRequest(Request $request, string &$jobId) : ?Response {
    // Check the credentials before doing anything else.
    $errorResponse = $this->checkAuthorization($request);
    if ($errorResponse !== NULL) return $errorResponse;

    $errorResponse = self::checkContentType($request);
    if ($errorResponse !== NULL) return $errorResponse;

    $jobId = '';
    return self::tryParseRequestBody($request, $jobId);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : static {
    $requestStack = $container->get('request_stack');
    assert($requestStack instanceof RequestStack);
    $tokenRetriever = $container->get('sermon_audio.site_token_retriever');
    assert($tokenRetriever instanceof SiteTokenRetriever);
    $finishedJobProcessor = $container->get('sermon_audio.finished_job_processor');
    assert($finishedJobProcessor instanceof FinishedJobProcessor);
    /** @phpstan-ignore-next-line */
    return new static($requestStack, $tokenRetriever, $finishedJobProcessor);
  }

  /**
   * Checks given request's Content-Type header.
   *
   * @return ?\Symfony\Component\HttpFoundation\Response
   *   NULL if the check passed, or an error response (415 or 400) if it didn't.
   */
  private static function checkContentType(Request $request) : ?Response {
    if (!$request->headers->has('Content-Type')) {
      return new Response('Missing Content-Type header.', 400);
    }
    $contentType = (string) $request->headers->get('Content-Type');
    if ($contentType === '') {
      return new Response('Empty Content-Type header.', 400);
    }

    $contentTypeParts = explode(';', $contentType);
    /** @phpstan-ignore-next-line */
    assert(is_array($contentTypeParts));

    $numContentTypeParts = count($contentTypeParts);
    if ($numContentTypeParts > 2) return self::getGenericMalformedContentTypeResponse();
    if (strcasecmp(trim($contentTypeParts[0]), 'application/json') !== 0) {
      return new Response('Content-Type header does not indicate a JSON request.', 415);
    }
    if ($numContentTypeParts > 1) {
      $secondContentTypePart = trim($contentTypeParts[1]);
      if ($secondContentTypePart !== '') {
        $charsetParts = explode('=', $secondContentTypePart);
        // This parameter should indicate a UTF-8 or US-ASCII character set.
        if (count($charsetParts) !== 2) {
          return self::getGenericMalformedContentTypeResponse();
        }
        if (strcasecmp(trim($charsetParts[0]), 'charset') !== 0) {
          return self::getGenericMalformedContentTypeResponse();
        }
        $characterSet = trim($charsetParts[1]);
        if (strcasecmp($characterSet, 'utf-8') !== 0 && strcasecmp($characterSet, 'us-ascii') !== 0) {
          return new Response('Content-Type header indicates an unsupported character set (neither ASCII nor UTF-8).', 415);
        }
      }
    }

    return NULL;
  }

  private static function getAuthorizationMalformedResponse() : Response {
    return new Response('Authorization header is malformed.', 400);
  }

  private static function getGenericMalformedContentTypeResponse() : Response {
    return new Response('Content-Type header is malformed.', 400);
  }

  /**
   * Gets a 401 response indicating request lacked valid "Bearer" credentials.
   */
  private static function getUnauthorizedResponse() : Response {
    return new Response('', 401, ['WWW-Authenticate' => 'Bearer']);
  }

  /**
   * Tries to extract the job ID from the given request's body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param string $jobId
   *   (output) Extracted job ID. Only set if request was valid.
   *
   * @return ?\Symfony\Component\HttpFoundation\Response
   *   Error response, or NULL if there was no error.
   */
  private static function tryParseRequestBody(Request $request, string &$jobId) : ?Response {
    $body = (string) $request->getContent();
    if ($body === '') {
      return new Response('Request body is empty.', 400);
    }

    $inputData = json_decode($body, TRUE);
    if (!is_array($inputData)) {
      return new Response('Request body is not valid JSON or has the wrong schema.', 400);
    }

    if (!isset($inputData['id'])) {
      return new Response('Request body does not contain a valid "id" property.', 400);
    }

    $id = $inputData['id'];
    if (!is_scalar($id)) {
      return new Response('Request body\'s "id" field is of the wrong type.', 400);
    }
    $id = (string) $id;

    if ($id === '') {
      return new Response('Request body\'s "id" field is empty.', 400);
    }

    $jobId = $id;
    return NULL;
  }

}
