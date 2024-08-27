<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\sermon_audio\Exception\ModuleConfigurationException;
use Psr\Http\Message\StreamInterface;
use Ranine\Exception\ParseException;
use Ranine\Helper\CastHelpers;

// @todo Eventually, we might want to handle quotation marks ("", etc.) when
// determining sentence breaks. Right now, we don't have Whisper set to even
// generate those, though.

/**
 * Downloads transcription XML and generates HTML output given an input sub-key.
 */
class FinalTranscriptionGenerator {

  /**
   * Paragraphs shorter than this are considered "pathological."
   */
  public const MIN_EXPECTED_PARAGRAPH_WORD_COUNT = 30;

  /**
   * Paragraphs larger than this are considered "pathological."
   *
   * @var int
   */
  public const MAX_EXPECTED_PARAGRAPH_WORD_COUNT = 700;

  /**
   * Maximum fluctuation from target when spitting large paragraph.
   *
   * @var int
   */
  public const SPLITTING_FLUCTUATION = 50;

  /**
   * Target average paragraph word count.
   *
   * @var int
   */
  public const TARGET_AVERAGE_PARAGRAPH_WORD_COUNT = 75;

  /**
   * Small value to assist in floating point comparisons.
   */
  private const EPSILON = 1E-4;

  /**
   * Module configuration.
   */
  private readonly ImmutableConfig $configuration;

  private readonly S3ClientFactory $s3ClientFactory;

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
   *   empty nor casts to a positive integer.
   * @throws \Aws\S3\Exception\S3Exception
   *   Thrown if an error occurs when attempting to make/receive a GET request
   *   for the transcription XML file.
   * @throws \RuntimeException
   *   Thrown if the transcription XML file body is missing or of the wrong
   *   type, or if the body is a PHP resource and does not appear to have valid
   *   metadata or could not be converted to a string, or if the body is a PSR
   *   stream and an error occurs when attempting to perform some operation on
   *   it.
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
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
    assert(is_array($s3GetResult) || $s3GetResult instanceof \ArrayAccess);
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
      if ($html !== '') $html .= "\n";
      $html .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, 'UTF-8') . '</p>';
    }

    return $html;
  }

  /**
   * Creates, prepares and returns a new XML parser.
   */
  private function createXmlParser() : \XMLParser {
    $parser = xml_parser_create('UTF-8');
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 1);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    return $parser;
  }

  /**
   * Estimates and returns the word count of the given (trimmed) text.
   */
  private static function estimateWordCount(string $text) : int {
    // Estimate word count by counting "separator" characters (whitespace) and
    // adding one. The input text should already be trimmed, so we don't have to
    // worry about that. We don't use str_word_count() because of the possible
    // existence of Unicode characters.
    $totalLength = strlen($text);
    $numSpaces = 0;
    for ($i = 0; $i < $totalLength; $i++) {
      if (ctype_space($text[$i])) $numSpaces++;
    }
    return $numSpaces + 1;
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

    return (int) round($center + $unitFluctuation * $maxFluctuation);
  }

  /**
   * Gets the transcription segments from the input transcription XML.
   *
   * @return \Drupal\sermon_audio\TranscriptionSegment[]
   *   Segments. Every segment is trimmed, and every segment except possibly the
   *   last ends in a sentence break character.
   *
   * @throws \Ranine\Exception\ParseException
   *   Thrown if the input XML has an unexpected or invalid format.
   * @throws \RuntimeException
   *   Thrown if an error occurs while parsing, or if the parser produces an
   *   array with an unexpected structure.
   */
  private function getSegmentsFromTranscriptionXml(string $xml) : array {
    $xml = trim($xml);
    if ($xml === '') return [];
    // We create a new parser every time we run this, as it seems parsers can't
    // be re-used.
    $parser = $this->createXmlParser();

    $parseOutput = [];
    $parseResultCode = xml_parse_into_struct($parser, $xml, $parseOutput);
    if ($parseResultCode !== 1) {
      $errorCode = xml_get_error_code($parser);
      /** @phpstan-ignore-next-line */
      throw new \RuntimeException('An error occurred during XML parsing.' . $errorCode ? (' Error code: ' . xml_error_string($errorCode) . '.') : '');
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

    // The segments produced may differ slightly from the segments in the XML.
    // In particular, we discard certain segments, and join a segment to its
    // successor if it does not end in a period (".").

    /** @var \Drupal\sermon_audio\TranscriptionSegment[] */
    $segments = [];
    /** @var float */
    $segmentStart = 0;
    /** @var float */
    $segmentEnd = 0;
    /** @var string */
    $segmentText = '';
    for ($i = 1; $i < $parseOutputLastIndex; $i++) {
      if (!isset($parseOutput[$i]['tag']) || !isset($parseOutput[$i]['type'])){
        throw new \RuntimeException('Unexpected XML parser output structure at index ' . $i . '.');
      }
      $tagInfo = $parseOutput[$i];
      if ($tagInfo['tag'] !== 'SEGMENT' || $tagInfo['type'] !== 'complete') {
        throw new ParseException('Transcription XML parse error: Invalid <transcription> sub-element at index ' . $i . '.');
      }

      if (!isset($tagInfo['attributes']['START']) || !isset($tagInfo['attributes']['END'])) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' is missing "start" and/or "end" attributes.');
      }
      $start = $tagInfo['attributes']['START'];
      $end = $tagInfo['attributes']['END'];
      if (!is_numeric($start) || !is_numeric($end)) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' has non-numeric "start" and/or "end" attributes.');
      }
      $start = (float) $start;
      $end = (float) $end;
      // If $start is a negative value, clean it up.
      if ($start < 0) $start = 0;
      // Force a proper ordering of segments.
      if ($start < $segmentEnd) $start = $segmentEnd; 
      // Tiny or too pathological segments are discarded.
      if ($end <= $start) continue;

      // Here we grab the segment text, and discard empty segments.
      if (isset($tagInfo['value'])) $text = $tagInfo['value'];
      else continue;
      if (!is_scalar($text) && $text !== NULL) {
        throw new \RuntimeException('Transcription XML parse error: <segment> tag at index ' . $i . ' has a non-scalar, non-NULL "value" attribute.');
      }
      $text = trim((string) $text);
      if ($text === '') continue;

      if ($segmentText === '') {
        $segmentStart = $start;
        $segmentEnd = $end;
        $segmentText = $text;
      }
      // Join the segments if the current output segment text does not end in a
      // period.
      elseif (!self::isSentenceBreakCharacter($segmentText[strlen($segmentText) - 1])) {
        $segmentEnd = $end;
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
    if ($segmentText !== '') {
      $segments[] = new TranscriptionSegment($segmentStart, $segmentEnd, $segmentText);
    }

    return $segments;
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
   * Tells if $char is a sentence break character.
   */
  private static function isSentenceBreakCharacter(string $char) : bool {
    return ($char === '.' || $char === '!' || $char === '?') ? TRUE : FALSE;
  }

  /**
   * Attempts to convert the given S3 body to a string.
   *
   * @param string|resource|\Psr\Http\Message\StreamInterface $body
   *
   * @throws \RuntimeException
   *   Thrown if the body is a PHP resource, but could not be converted to a
   *   string.
   * @throws \RuntimeException
   *   Thrown if an error occurs when attempting to read from or rewind the PSR
   *   stream $body.
   */
  private static function s3BodyToString(mixed $body) : string {
    if (is_resource($body)) {
      // Here and below, we try to seek to the beginning of the stream. This is
      // because an example in the AWS documentation at https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/php_s3_code_examples.html
      // seems to suggest that might be necessary, though I suspect it isn't.
      // First we try to determine if the stream is seekable.
      $streamMetadata = stream_get_meta_data($body);
      $result = stream_get_contents($body, NULL, empty($streamMetadata['seekable']) ? -1 : 0);
      if (!is_string($result)) {
        throw new \RuntimeException('Failed to convert S3 stream body to a string.');
      }
      return $result;
    }
    elseif ($body instanceof StreamInterface) {
      if ($body->isSeekable()) $body->rewind();
      return $body->getContents();
    }
    else {
      assert(is_string($body));
      return $body;
    }
  }

  /**
   * Converts the given ordered array of segments to a series of paragraphs.
   *
   * @param array<int, \Drupal\sermon_audio\TranscriptionSegment> $segments
   *   Ordered array of segments to convert. Indexed consecutively from 0. The
   *   text in each segment should be trimmed, and should end (with the possible
   *   exception of the last segment) with a sentence break character.
   *
   * @return iterable<string>
   *   Output paragraphs. Need to be escaped before being added to HTML.
   *
   * @throws \InvalidArgumentException
   *   Thrown if $segments[$i] does not exist for 0 <= $i < count($segments).
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
   */
  private static function segmentsToParagraphs(array $segments) : iterable {
    $numSegments = count($segments);
    if ($numSegments === 0) return [];

    // Start by computing the word counts of all the segments. We'll need that
    // information later.
    /** @var array<int, int> */
    $wordCounts = [];
    $totalWordCount = 0;
    for ($i = 0; $i < $numSegments; $i++) {
      if (!isset($segments[$i])) {
        throw new \InvalidArgumentException('Segment at index ' . $i . ' does not exist, but should, as $segments should be a consecutively numerically keyed array.');
      }
      $wordCount = self::estimateWordCount($segments[$i]->getText());
      $wordCounts[$i] = $wordCount;
      $totalWordCount += $wordCount;
    }

    // The keys are the paragraph IDs, and the values are the last segment
    // indices in the respective paragraphs.
    /** @var array<int, int> */
    $lastSegmentIdsInParagraphs = [];
    // Keys are the paragraph IDs.
    $longParagraphWordCounts = [];

    if ($numSegments === 1) {
      $lastSegmentIdsInParagraphs[0] = 0;
      if ($totalWordCount > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
        $longParagraphWordCounts[0] = $totalWordCount;
      }
    }
    else {
      // Next, compute separation times between segments. The keys are the indices
      // of the segments preceding the respective separations.
      /** @var array<int, float> */
      $segmentSeparations = [];
      for ($i = 0, $j = 1; $j < $numSegments; $i++, $j++) {
        $separation = $segments[$j]->getStart() - $segments[$i]->getEnd();
        $segmentSeparations[$i] = $separation;
      }

      // We now make a sorted list of unique separations (for a reason which
      // will become shortly apparent). We add on a small "fake" value to the
      // top of the list to make sure we also have the extreme case of a
      // separation that results in a single paragraph.
      // (This should really be done using insertions into something like a
      // red-black tree, but it is not worth it to implement such a thing in
      // PHP).
      $sortedPossibleSeparations = array_unique($segmentSeparations, SORT_NUMERIC);
      sort($sortedPossibleSeparations, SORT_NUMERIC);
      $sortedPossibleSeparations[] = $sortedPossibleSeparations[count($sortedPossibleSeparations) - 1] + 1;

      // Compute the paragraph breaks, and note the pathologically long
      // paragraphs that result from our breaks. If there is just one segment,
      // we don't need to do much.

      // Estimate the optimal separation time using the following midpoint
      // algorithm:
      // 1) Pick a separation time in the middle of the sorted list above.
      // 2a) Compute the word count of the pathologically short and
      //     pathologically long paragraphs that would result from a division
      //     generated by that separation time.
      // 2b) If there are no pathological paragraphs in step 2a), compute the
      //     distance between the total word count and our target value (target
      //     average paragraph word count * num paragraphs). If the distance is
      //     zero, we are done.
      // 3) If the results of steps 2a) and 2b) indicate the paragraphs are too
      //    short or too long, adjust the separation time up or down
      //    (respectively) by picking another separation from the middle of the
      //    corresponding "candidate times" subset of the sorted list (this
      //    subset shrinks by roughly half each iteration). If this subset is of
      //    size one, we are done.
      // 4) Otherwise, repeat from step 2a).
      // The algorithm should run in O(n * log n) time, where n is the number of
      // segments.
      $candidateStartIndex = 0;
      $candidateEndIndex = count($sortedPossibleSeparations) - 1;
      $lastSegmentIndex = $numSegments - 1;
      while ($candidateStartIndex < $candidateEndIndex) {
        // A small value is added to the argument of floor() to ensure integer
        // midpoints are handled correctly (and not incorrectly rounded down to
        // the integer below). Note that using floor() instead of ceil() here is
        // appropriate, as we prefer to have larger paragraphs (which are split
        // apart) than to have smaller ones (which we don't join). By using
        // floor(), the smaller values tend to be tested (and rejected) first.
        $testIndex = (int) floor(($candidateStartIndex + $candidateEndIndex) / 2 + 0.1);
        // Subtract a small value to ensure the separation comparisons catch
        // instances where the semgent separation is equal to the test
        // separation.
        $testSeparation = $sortedPossibleSeparations[$testIndex] - self::EPSILON;

        // Compute the number of paragraphs and the pathalogical word counts
        // from the test separation.
        $wordCountCurrentParagraph = 0;
        $smallParagraphsTotalWords = 0;
        $largeParagraphsTotalWords = 0;
        $numParagraphs = 0;
        for ($i = 0; $i < $numSegments; $i++) {
          $wordCountCurrentParagraph += $wordCounts[$i];
          if ($i === $lastSegmentIndex || $segmentSeparations[$i] > $testSeparation) {
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
          // Separation may be too small.
          // This if() is necessary to ensure the start index is updated if the
          // current gap is of size one.
          if ($candidateStartIndex === $testIndex) $candidateStartIndex++;
          else $candidateStartIndex = $testIndex;
        }
        elseif ($largeParagraphsTotalWords === 0) {
          // Check diff of total word count and optimal value. Too small or too
          // big?
          $diff = $totalWordCount - self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT * $numParagraphs;
          if ($diff > self::EPSILON) $candidateEndIndex = $testIndex;
          elseif ($diff < -self::EPSILON) {
            if ($candidateStartIndex === $testIndex) $candidateStartIndex++;
            else $candidateStartIndex = $testIndex;
          }
          else {
            // Optimal!
            $candidateStartIndex = $testIndex;
            $candidateEndIndex = $testIndex;
          }
        }
        else {
          // Separation may be too large.
          $candidateEndIndex = $testIndex;
        }
      }
      assert($candidateStartIndex === $candidateEndIndex);
      // We subtract epsilon for the same reason as before.
      $separation = $sortedPossibleSeparations[$candidateStartIndex] - self::EPSILON;

      $wordCountCurrentParagraph = 0;
      $paragraphId = 0;

      for ($i = 0; $i < $lastSegmentIndex; $i++) {
        $wordCountCurrentParagraph += $wordCounts[$i];
        if ($segmentSeparations[$i] > $separation) {
          $lastSegmentIdsInParagraphs[$paragraphId] = $i;
          if ($wordCountCurrentParagraph > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
            $longParagraphWordCounts[$paragraphId] = $wordCountCurrentParagraph;
          }
          $wordCountCurrentParagraph = 0;
          $paragraphId++;
        }
      }
      $lastSegmentIdsInParagraphs[$paragraphId] = $lastSegmentIndex;
      $wordCountCurrentParagraph += $wordCounts[$lastSegmentIndex];
      if ($wordCountCurrentParagraph > self::MAX_EXPECTED_PARAGRAPH_WORD_COUNT) {
        $longParagraphWordCounts[$paragraphId] = $wordCountCurrentParagraph;
      }
    }

    // Finally, create the paragraphs, splitting apart the long ones.
    $paragraphStart = 0;
    foreach ($lastSegmentIdsInParagraphs as $paragraphId => $lastSegmentIdInParagraph) {
      if (isset($longParagraphWordCounts[$paragraphId])) {
        $wordCountNotOutputtedAsParagraphs = $longParagraphWordCounts[$paragraphId];
        $paragraph = '';
        $paragraphWordCount = 0;
        $targetParagraphSize = self::getRandomIntWithFluctuations(self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT, self::SPLITTING_FLUCTUATION);
        if (($wordCountNotOutputtedAsParagraphs - $targetParagraphSize) < self::MIN_EXPECTED_PARAGRAPH_WORD_COUNT) {
          $targetParagraphSize = $wordCountNotOutputtedAsParagraphs;
        }
        // Split along sentence boundaries.
        foreach (self::appendSentencesFromSegments((function () use($segments, $wordCounts, $paragraphStart, $lastSegmentIdInParagraph) : iterable {
          for ($i = $paragraphStart; $i <= $lastSegmentIdInParagraph; $i++) {
            assert($wordCounts[$i] > 0);
            yield $segments[$i]->getText() => $wordCounts[$i];
          }
        })(), $paragraph) as $wordCount) {
          $paragraphWordCount += $wordCount;
          if ($paragraphWordCount >= $targetParagraphSize) {
            // Output the current paragraph and start a new one.
            yield $paragraph;
            $wordCountNotOutputtedAsParagraphs -= $paragraphWordCount;
            if ($wordCountNotOutputtedAsParagraphs <= 0) break;
            $paragraph = '';
            $paragraphWordCount = 0;
            $targetParagraphSize = self::getRandomIntWithFluctuations(self::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT, self::SPLITTING_FLUCTUATION);
            if (($wordCountNotOutputtedAsParagraphs - $targetParagraphSize) < self::MIN_EXPECTED_PARAGRAPH_WORD_COUNT) {
              $targetParagraphSize = $wordCountNotOutputtedAsParagraphs;
            }
          }
        }
      }
      else {
        $paragraph = '';
        for ($i = $paragraphStart; $i <= $lastSegmentIdInParagraph; $i++) {
          if ($paragraph !== '') $paragraph .= ' ';
          $paragraph .= $segments[$i]->getText();
        }
        yield $paragraph;
      }
      $paragraphStart = $lastSegmentIdInParagraph + 1;
    }
  }

  /**
   * Appends sentences from the given segments to the given string.
   *
   * Also trims each sentence and separates them with spaces.
   *
   * @param iterable<string, int> $segments
   *   Keys are the segments, and values are the corresponding word counts.
   * @phpstan-param iterable<non-empty-string, positive-int> $segments
   * @param string $toAppendTo
   *   (reference) String to append to. Can be modified by caller in between
   *   iterator yields.
   *
   * @return iterable<int>
   *   Word counts of sentences just appended to $toAppendTo.
   *
   * @throws \RuntimeException
   *   Thrown if a regex error occurs.
   */
  private static function appendSentencesFromSegments(iterable $segments, string &$toAppendTo) : iterable {
    if ($segments === []) return [];

    $matches = [];
    $runningSentence = '';
    $runningSentenceWordCount = 0;
    foreach ($segments as $segment => $segmentWordCount) {
      assert($segment !== '');

      $numMatches = preg_match_all('/(?:.*?[.!?]+\s)|(?:.+$)/', $segment, $matches, PREG_SET_ORDER);
      if ($numMatches === FALSE) {
        throw new \RuntimeException('Sentence matching regex error.');
      }
      assert(is_int($numMatches));
      assert($numMatches > 0);

      $currentMatchIndex = 0;
      // If we can, terminate the current "running sentence" and append it (and
      // output its word count).
      if ($runningSentence !== '') {
        $trimmedFirstMatch = trim($matches[0][0]);
        assert($trimmedFirstMatch !== '');
        if ($numMatches > 1 || self::isSentenceBreakCharacter($trimmedFirstMatch[strlen($trimmedFirstMatch) - 1])) {
          if ($toAppendTo !== '') $toAppendTo .= ' ';
          $toAppendTo .= $runningSentence . ' ' . $trimmedFirstMatch;
          if ($numMatches === 1) {
            yield $runningSentenceWordCount + $segmentWordCount;
          }
          else {
            yield $runningSentenceWordCount + self::estimateWordCount($trimmedFirstMatch);
          }

          $currentMatchIndex = 1;
          $runningSentence = '';
          $runningSentenceWordCount = 0;
        }
      }

      $lastMatchIndex = $numMatches - 1;
      for ($currentMatchIndex; $currentMatchIndex < $lastMatchIndex; $currentMatchIndex++) {
        $trimmedMatch = trim($matches[$currentMatchIndex][0]);
        assert($trimmedMatch !== '');
        if ($toAppendTo !== '') $toAppendTo .= ' ';
        $toAppendTo .= $trimmedMatch;
        yield self::estimateWordCount($trimmedMatch);
      }

      // If there was only one match, we may have already handled it in the
      // "running sentence" code earlier, so we add this if() statement.
      if ($lastMatchIndex === $currentMatchIndex) {
        // For the last match, append if it ends in a sentence break character.
        $trimmedLastMatch = trim($matches[$lastMatchIndex][0]);
        if (self::isSentenceBreakCharacter($trimmedLastMatch[strlen($trimmedLastMatch) - 1])) {
          if ($toAppendTo !== '') $toAppendTo .= ' ';
          $toAppendTo .= $trimmedLastMatch;
          // No need to estimate the word count if the match consists of the
          // entire segment.
          if ($numMatches === 1) yield $segmentWordCount;
          else yield self::estimateWordCount($trimmedLastMatch);
        }
        else {
          // Add to the current "running sentence."
          if ($runningSentence !== '') $runningSentence .= ' ';
          $runningSentence .= $trimmedLastMatch;
          if ($numMatches === 1) $runningSentenceWordCount += $segmentWordCount;
          else $runningSentenceWordCount += self::estimateWordCount($trimmedLastMatch);
        }
      }
    }
    // Append the last "running sentence" if it exists.
    if ($runningSentence !== '') {
      if ($toAppendTo !== '') $toAppendTo .= ' ';
      $toAppendTo .= $runningSentence;
      yield $runningSentenceWordCount;
    }
  }

}
