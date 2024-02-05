<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Helper\CastHelpers;
use Psr\Http\Message\StreamInterface;
use Ranine\Exception\ParseException;
use Ranine\Iteration\ExtendableIterable;

/**
 * Downloads transcription XML and generates HTML output given an input sub-key.
 */
class FinalTranscriptionGenerator {

  /**
   * Resolution for floating-point comparisons.
   *
   * @var float
   */
  private const EPSILON = 1E-6;

  /**
   * Paragraphs shorter than this are considered "pathological."
   */
  private const MIN_EXPECTED_PARAGRAPH_WORD_COUNT = 30;

  /**
   * Minimum separation time (in seconds) between transcription segments.
   *
   * Segments closer than this are joined together.
   *
   * @var float
   */
  private const MIN_SEGMENT_SEPARATION = 5E-2;

  /**
   * Paragraphs larger than this are considered "pathological."
   *
   * @var int
   */
  private const MAX_EXPECTED_PARAGRAPH_WORD_COUNT = 700;

  /**
   * Maximum fluctuation from target when spitting large paragraph.
   *
   * @var int
   */
  private const SPLITTING_FLUCTUATION = 50;

  /**
   * Target average paragraph word count.
   *
   * @var int
   */
  private const TARGET_AVERAGE_PARAGRAPH_WORD_COUNT = 75;

  /**
   * Module configuration.
   */
  private readonly ImmutableConfig $configuration;

  /**
   * Amazon S3 client factory.
   */
  private readonly S3ClientFactory $s3ClientFactory;

  /**
   * XML parser, if it is set yet.
   */
  private ?\XMLParser $xmlParser = NULL;

  /**
   * Creates a new final transcription generator.
   *
   * @param S3ClientFactory $s3ClientFactory
   *   Amazon S3 client factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(S3ClientFactory $s3ClientFactory, ConfigFactoryInterface $configFactory) {
    $this->s3ClientFactory = $s3ClientFactory;
    $this->configuration = $configFactory->get('sermon_audio.settings');
  }

  /**
   * Generates transcription HTML from input transcription XML sub-key.
   *
   * @param string $inputTranscriptionXmlSubKey
   *   Input transcription XML sub-key.
   *
   * @return string
   *   Transcription HTML.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the "transcription_s3_aws_region", "transcription_key_prefix"
   *   or "transcription_bucket_name" setting is missing or empty.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Can be thrown if this module's "aws_credentials_file_path" configuration
   *   setting is not empty and not whitespace yet points to an invalid or
   *   missing credentials file.
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the module's "connect_timeout" configuration setting is neither
   *   empty nor castable to a positive integer.
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a GET request
   *   for the transcription XML file.
   * @throws \RuntimeException
   *   Thrown if the transcription XML file body is missing or of the wrong
   *   type, or if the body is a PHP resource and does not appear to have valid
   *   metadata or could not be converted to a string, or if the body is a PSR
   *   stream and an error occurs when attempting to perform some operation on
   *   it.
   */
  public function generateTranscriptionHtml(string $inputTranscriptionXmlSubKey) : string {
    $s3Client = $this->s3ClientFactory->getClient($this->getTranscriptionS3Region());
    $s3Bucket = CastHelpers::stringyToString($this->configuration->get('transcription_bucket_name'));
    if ($s3Bucket === '') {
      throw new ModuleConfigurationException('The "transcription_bucket_name" module setting is missing or empty.');
    }
    $s3KeyPrefix = CastHelpers::stringyToString($this->configuration->get('transcription_key_prefix'));
    if ($s3KeyPrefix === '') {
      throw new ModuleConfigurationException('The "transcription_key_prefix" module setting is missing or empty.');
    }

    $s3GetResult = $s3Client->getObject(['Bucket' => $s3Bucket, 'Key' => $s3KeyPrefix . $inputTranscriptionXmlSubKey]);
    if (!isset($s3GetResult['Body'])) {
      throw new \RuntimeException('The transcription XML file body is missing.');
    }
    $body = $s3GetResult['Body'];
    if (!is_resource($body) && !is_string($body) && !($body instanceof StreamInterface)) {
      throw new \RuntimeException('The transcription XML file body is of the wrong type.');
    }
    $transcriptionXml = self::s3BodyToString($body);

    $segments = $this->getSegmentsFromTranscriptionXml($transcriptionXml);
    if ($segments === []) return '';

    $html = '';
    foreach (self::segmentsToParagraphs($segments) as $paragraph) {
      $html .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, 'UTF-8') . "</p>\n";
    }

    return $html;
  }

  /**
   * Ensures the XML parser is created.
   */
  private function ensureXmlParserCreated() : void {
    if (!isset($this->xmlParser)) {
      $this->xmlParser = new \XMLParser('UTF-8');
      xml_parser_set_option($this->xmlParser, XML_OPTION_CASE_FOLDING, TRUE);
      xml_parser_set_option($this->xmlParser, XML_OPTION_SKIP_WHITE, TRUE);
    }
  }

  /**
   * Gets the transcription segments from the input transcription XML.
   *
   * @param string $xml
   *   XML to parse.
   *
   * @return \Drupal\sermon_audio\TranscriptionSegment[]
   *
   * @throws \Ranine\Exception\ParseException
   *   Thrown if the input XML as an unexpected or invalid format.
   * @throws \RuntimeException
   *   Thrown if an error occurs while parsing, or if the parser produces an
   *   array with an unexpected structure.
   */
  private function getSegmentsFromTranscriptionXml(string $xml) : array {
    $xml = trim($xml);
    if ($xml === '') return [];
    $this->ensureXmlParserCreated();

    $parseOutput = [];
    $parseResultCode = xml_parse_into_struct($this->xmlParser, $xml, $parseOutput);
    if ($parseResultCode !== 1) {
      throw new \RuntimeException('An error occurred during XML parsing.');
    }

    $parseOutputLastIndex = count($parseOutput) - 1;
    if (!isset($parseOutput[0]['tag'])
      || !isset($parseOutput[0]['type'])
      || !isset($parseOutput[$parseOutputLastIndex]['tag'])
      || !isset($parseOutput[$parseOutputLastIndex]['type'])) {
      throw new \RuntimeException('Unexpected XML parser output structure.');
    }
    if ($parseOutput[0]['tag'] !== 'TRANSCRIPTION' || $parseOutput[0]['type'] !== 'open') {
      throw new ParseException('Transcription XML does not have a valid opening <transcription> tag.');
    }
    if ($parseOutput[$parseOutputLastIndex]['tag'] !== 'TRANSCRIPTION' || $parseOutput[$parseOutputLastIndex]['type'] !== 'close') {
      throw new ParseException('Transcription XML does not have a valid closing <transcription> tag.');
    }

    /** @var \Drupal\sermon_audio\TranscriptionSegment[] */
    $segments = [];
    /** @var ?float */
    $segmentStart = NULL;
    /** @var ?float */
    $segmentEnd = NULL;
    /** @var ?string */
    $segmentText = NULL;
    for ($i = 1; $i < $parseOutputLastIndex; $i++) {
      if (!isset($parseOutput[$i]['tag']) || !isset($parseOutput[$i]['type']) || !isset($parseOutput[$i]['value'])){
        throw new \RuntimeException('Unexpected XML parser output structure at index ' . $i . '.');
      }
      $tagInfo = $parseOutput[$i];
      if ($tagInfo['tag'] !== 'SEGMENT' || $tagInfo['type'] !== 'complete') {
        throw new ParseException('Transcription XML parse error: Invalid <transcription> sub-element at index ' . $i . '.');
      }

      if (!isset($tagInfo['attributes']['start']) || !isset($tagInfo['attributes']['end'])) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' is missing "start" and/or "end" attributes.');
      }
      $start = $tagInfo['attributes']['start'];
      $end = $tagInfo['attributes']['end'];
      if (!is_numeric($start) || !is_numeric($end)) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' has non-numeric "start" and/or "end" attributes.');
      }
      $start = (float) $start;
      $end = (float) $end;
      // If $start is a small negative value, clean it up.
      if ($start < 0 && $start > -self::EPSILON) $start = 0;
      // Tiny segments are discarded. @todo Warn?
      if ($end <= $start) continue;

      $text = $tagInfo['value'];
      if (!is_scalar($text) && $text !== NULL) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' has a non-scalar, non-NULL "value" attribute.');
      }
      $text = trim((string) $text);
      if ($text === '') continue;

      if ($segmentStart === NULL) {
        $segmentStart = $start;
        $segmentEnd = $end;
        $segmentText = $text;
      }
      else {
        // We merge together very nearby segments or segments that are not
        // separated with a period (".").
        if (($start - $segmentEnd) < self::MIN_SEGMENT_SEPARATION || $segmentText[count($segmentText) - 1] !== '.') {
          // We use max() in case $end < $segmentEnd for some strange reason.
          $segmentEnd = (float) max($end, $segmentEnd);
          $segmentText .= ' ' . $text;
        }
        else {
          // Save the previous segment and start a new one.
          $segments[] = new TranscriptionSegment($segmentStart, $segmentEnd, $segmentText);
          $segmentStart = $start;
          $segmentEnd = $end;
          $segmentText = $text;
        }
      }
    }
    if ($segmentStart !== NULL) {
      $segments[] = new TranscriptionSegment($segmentStart, $segmentEnd, $segmentText);
    }

    return $segments;
  }

  /**
   * Gets a random integer with a non-uniform "tent" probability distribution.
   *
   * @param int $center
   *   Center of distribution.
   * @param int $maxFluctuation
   *   Maximum deviation from center.
   */
  private static function getRandomIntWithFluctuations(int $center, int $maxFluctuation) : int {
    $unitNoise = rand() / getrandmax();

    // Use inverse transform sampling on a linear "tent" distribution (to keep
    // things simple haha).
    if ($unitNoise > 0.5) $unitFluctuation = 1 - sqrt(2 * (1 - $unitNoise));
    else $unitFluctuation = sqrt(2 * $unitNoise) - 1;

    return $center + $unitFluctuation * $maxFluctuation;
  }

  /**
   * Gets the transcription S3 region from the module configuration.
   *
   * @throws \Drupal\sermon_audio\Exception\ModuleConfigurationException
   *   Thrown if the "transcription_s3_aws_region" setting is missing or empty.
   */
  private function getTranscriptionS3Region() : string {
    $region = CastHelpers::stringyToString($this->configuration->get('transcription_s3_aws_region'));
    if ($region === '') {
      throw new ModuleConfigurationException('The "transcription_s3_aws_region" module setting is missing or empty.');
    }
    return $region;
  }

  /**
   * Estimates and returns the word count of the given (trimmed) text.
   */
  private static function estimateWordCount(string $text) : int {
    // Estimate word count by counting "separator" characters (whitespace) and
    // adding one. The input text should already be trimmed, so we don't have to
    // worry about that. We don't use str_word_count() because of the possible
    // existence of Unicode characters.
    return ExtendableIterable::from($text)
        ->filter(fn(string $c) => ctype_space($c))
        ->count() + 1;
  }

  /**
   * Attempts to convert the given S3 body to a string.
   *
   * @throws \RuntimeException
   *   Thrown if the body is a PHP resource, but a call to
   *   stream_get_meta_data() does not return a valid response.
   * @throws \RuntimeException
   *   Thrown if the body is a PHP resource, but could not be converted to a
   *   string.
   * @throws \RuntimeException
   *   Thrown if an error occurs when attempting to read from or rewind the PSR
   *   stream $body.
   */
  private static function s3BodyToString(string|resource|StreamInterface $body) : string {
    if (is_resource($body)) {
      // Here and below, we try to seek to the beginning of the stream. This is
      // because an example in the AWS documentation at https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/php_s3_code_examples.html
      // seems to suggest that might be necessary, though I suspect it isn't.
      // First we try to determine if the stream is seekable.
      $streamMetadata = stream_get_meta_data($body);
      if (!is_array($streamMetadata) || !array_key_exists('seekable', $streamMetadata)) {
        throw new \RuntimeException('S3 stream body does not appear to have valid metadata.');
      }
      $result = stream_get_contents($body, NULL, empty($streamMetadata['seekable']) ? -1 : 0);
      if (!is_string($result)) {
        throw new \RuntimeException('Failed to convert S3 stream body to a string.');
      }
      return $result;
    }
    elseif ($body instanceof StreamInterface) {
      if ($body->isSeekable()) $body->rewind();
      $result = $body->getContents();
    }
    else return $body;
  }

  /**
   * Converts the given ordered array of segments to a series of paragraphs.
   *
   * @param array<int, \Drupal\sermon_audio\TranscriptionSegment> $segments
   *   Ordered array of segments to convert. Indexed consecutively from 0. The
   *   text in each segment should be trimmed, and should end with a period
   *   ("."). Segments closer than some the minimum separation should already be
   *   joined.
   *
   * @return iterable<string>
   *   Output paragraphs. Need to be escaped before being added to HTML.
   *
   * @throws \InvalidArgumentException
   *   Can be thrown if $segments[$i] does not exist for 0 <= $i <
   *   count($segments).
   */
  private static function segmentsToParagraphs(array $segments) : iterable {
    $numSegments = count($segments);

    // Start by computing the word counts of all the segments. We'll need that
    // information later.
    /** @var array<int, int> */
    $wordCounts = [];
    $totalWordCount = 0;
    for ($i = 0; $i < $numSegments; $i++) {
      $wordCount = self::estimateWordCount($segments[$i]->getText());
      $wordCounts[$i] = $wordCount;
      $totalWordCount += $wordCount;
    }

    // Next, compute separation times between segments. The keys are the indices
    // of the segments preceding the respective separations.
    /** @var array<int, float> */
    $segmentSeparations = [];
    for ($i = 0, $j = 1; $j < $numSegments; $i++, $j++) {
      $separation = $segments[$j]->getStart() - $segments[$i]->getEnd();
      $segmentSeparations[$i] = $separation;
    }
    // We now make a sorted list of separations (for a reason which will become
    // shortly apparent).
    $sortedSeparations = $segmentSeparations;
    sort($sortedSeparations, SORT_NUMERIC);

    // Estimate the optimal separation time using the following midpoint
    // algorithm:
    // 1) Pick a separation time in the middle of the sorted list above.
    // 2a) Compute the word count of the pathologically short and pathologically
    //     long paragraphs that would result from a division generated by that
    //     separation time.
    // 2b) If there are no pathological paragraphs in step 2a), compute the
    //     total word count of the paragraphs, and its distance from our target
    //     value (target average paragraph word count * num paragraphs). If the
    //     distance is zero, we are done.
    // 3) If the results of steps 2a) and 2b) indicate the paragraphs are too
    //    short or too long, adjust the separation time up or down
    //    (respectively) by picking another separation from the middle of the
    //    corresponding "candidate times" subset of the sorted list (this subset
    //    shrinks by roughly half each iteration). If this subset is of size
    //    one, we are done.
    // 4) Otherwise, repeat from step two.
    // The algorithm should run in O(n * log n) time, where n is the number of
    // segments.
    $candidateStartIndex = 0;
    $candidateEndIndex = count($sortedSeparations) - 1;
    $lastSegmentIndex = $numSegments - 1;
    /** @var ?int */
    $testIndex = NULL;
    while ($candidateStartIndex < $candidateEndIndex) {
      // A small value is added to the argument of floor() to ensure integer
      // midpoints are handled correctly (and not incorrectly rounded down to
      // the integer below).
      $testIndex = (int) floor(($candidateStartIndex + $candidateEndIndex) / 2 + 0.1);
      $testSeparation = $sortedSeparations[$testIndex];

      // Compute the number of paragraphs and the pathalogical word counts from
      // the test separation.
      $wordCountCurrentParagraph = 0;
      $smallParagraphsTotalWords = 0;
      $largeParagraphsTotalWords = 0;
      $numParagraphs = 0;
      for ($i = 0; $i < $numSegments; $i++) {
        $wordCountCurrentParagraph += $wordCounts[$i];
        if ($segmentSeparations[$i] > $testSeparation || $i === $lastSegmentIndex) {
          if ($wordCountCurrentParagraph < self::MIN_EXPECTED_PARAGRAPH_WORD_COUNT) {
            $smallParagraphsTotalWords += $wordCountCurrentParagraph;
          }
          elseif ($wordCountCurrentParagraph > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
            $largeParagraphsTotalWords += $wordCountCurrentParagraph;
          }

          $numParagraphs++;
          $wordCountCurrentParagraph = 0;
        }
      }
      if ($smallParagraphsTotalWords > $largeParagraphsTotalWords) {
        // Separation may be too large.
        $candidateEndIndex = $testIndex;
      }
      elseif ($largeParagraphsTotalWords === 0) {
        // Check diff of total word count and optimal value. Too small or too
        // big?
        $diff = $totalWordCount - self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT * $numParagraphs;
        if ($diff > 0) $candidateEndIndex = $testIndex;
        elseif ($diff < 0) {
          // This if() is necessary to ensure the start index is updated if the
          // current gap is of size one.
          if ($candidateStartIndex === $testIndex) $candidateStartIndex++;
          else $candidateStartIndex = $testIndex;
        }
        else {
          // Optimal!
          break;
        }
      }
      else {
        // Separation may be too small.
        if ($candidateStartIndex === $testIndex) $candidateStartIndex++;
        else $candidateStartIndex = $testIndex;
      }
    }
    if ($textIndex === NULL) $separation = $sortedSeparations[$candidateStartIndex];
    else $separation = $testSeparation;

    // Compute the paragraph breaks, and note the pathologically long
    // paragraphs.
    // The keys are the paragraph IDs, and the values are the segment indices
    // following the paragraphs.
    /** @var array<int, int> */
    $breaksByParagraph = [];
    /** @var array<int, bool> */
    $longParagraphWordCounts = [];
    $wordCountCurrentParagraph = 0;
    $paragraphId = 0;
    for ($i = 0; $i < $lastSegmentIndex; $i++) {
      $wordCountCurrentParagraph += $wordCounts[$i];
      if ($segmentSeparations[$i] > $separation) {
        $breaksByParagraph[$paragraphId] = $i;
        if ($wordCountCurrentParagraph > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
          $longParagraphWordCounts[$paragraphId] = $wordCountCurrentParagraph;
        }
        $wordCountCurrentParagraph = 0;
        $paragraphId++;
      }
    }
    // We use a sentinel value to indicate the "break" following the last
    // paragraph.
    $breaksByParagraph[$paragraphId] = $lastSegmentIndex + 1;
    $wordCountCurrentParagraph += $wordCounts[$lastSegmentIndex];
    if ($wordCountCurrentParagraph > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
      $longParagraphWordCounts[$paragraphId] = $wordCountCurrentParagraph;
    }

    // Finally, create the paragraphs, splitting apart the long ones.
    $paragraphStart = 0;
    foreach ($breaksByParagraph as $paragraphId => $break) {
      if (isset($longParagraphWordCounts[$paragraphId])) {
        yield from self::splitLongParagraph((function () use ($segments, $break, $paragraphStart, $lastSegmentIndex) : iterable {
          for ($i = $paragraphStart; $i < $break; $i++) {
            $segmentText = $segments[$paragraphStart];
            yield strtok($segmentText, '.') . '.';
            while (($sentence = strtok('.')) !== FALSE) yield ltrim($sentence) . '.';
            // If the segment doesn't end in a period, don't add a period.
            if ($segmentText[count($segmentText) - 1] === '.') yield ltrim($sentence) . '.';
            else yield ltrim($sentence);
          }
        })(), $longParagraphWordCounts[$i]);
      }
      else {
        $paragraph = '';
        for ($i = $paragraphStart; $i < $break; $i++) {
          $paragraph .= $segments[$i]->getText();
        }
        yield $paragraph;
      }
      $paragraphStart = $break;
    }
  }

  /**
   * Splits apart a long paragraph.
   *
   * The paragraph sizes are (roughly speaking) determined with a probability
   * distribution whose average is our target value, and whose off-average
   * values are described by a linear distribution with a finite cutoff.
   *
   * @param iterable<string> $sentences
   *   Sentences to join. Should all be trimmed.
   * @param int $totalWordCount
   *   Total word count of the long paragraph we want to split.
   * @phpstan-param positive-int $totalWordCount
   *
   * @return iterable<string>
   *   Output paragraphs.
   */
  private static function splitLongParagraph(iterable $sentences, int $totalWordCount) : iterable {
    assert($totalWordCount > 0);

    $wordCountFutureParagraphs = $totalWordCount;
    $currentParagraph = '';
    $currentParagraphWordCount = 0;
    $targetParagraphSize = self::getRandomIntWithFluctuations(self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT, self::SPLITTING_FLUCTUATION);
    foreach ($sentences as $sentence) {
      $currentParagraph .= ($currentParagraph === '' ? $sentence : ' ' . $sentence);
      if ($remainingWordCount > $targetParagraphSize) {
        $currentParagraphWordCount += self::estimateWordCount($sentence);
        if ($currentParagraphWordCount >= $targetParagraphSize) {
          // Output the current paragraph and start a new one.
          yield $currentParagraph;
          $currentParagraph = '';
          $currentParagraphWordCount = 0;
          $wordCountFutureParagraphs -= $currentParagraphWordCount;
          $targetParagraphSize = self::getRandomIntWithFluctuations(self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT, self::SPLITTING_FLUCTUATION);
        }
      }
    }

    yield $currentParagraph;
  }

}
