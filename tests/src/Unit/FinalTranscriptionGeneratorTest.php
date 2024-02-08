<?php

declare(strict_types = 1);

namespace Drupal\Tests\sermon_audio\Unit;

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Drupal\sermon_audio\FinalTranscriptionGenerator;
use Drupal\Tests\sermon_audio\Traits\SampleTranscriptionDataTrait;
use Drupal\Tests\UnitTestCase;
use Ranine\Testing\Drupal\Traits\MockConfigFactoryCreationTrait;

/**
 * @coversDefaultClass \Drupal\sermon_audio\FinalTranscriptionGenerator
 * @group sermon_audio
 */
class FinalTranscriptionGeneratorTest extends UnitTestCase {

  use MockConfigFactoryCreationTrait, SampleTranscriptionDataTrait;

  /** @var string */
  private const TRANSCRIPTION_BUCKET_NAME = 'bucket';
  /** @var string */
  private const TRANSCRIPTION_KEY_PREFIX = 'key-prefix/';
  /** @var string */
  private const TRANSCRIPTION_S3_AWS_REGION = 'us-east-1';

  private FinalTranscriptionGenerator $finalTranscriptionGenerator;

  /**
   * Tests the generate() method for the datum w/ empty or very short segments.
   *
   * @covers ::generate
   */
  public function testGenerateEmptyOrVeryShortSegmentsDatum() : void {
    $result = $this->finalTranscriptionGenerator->generateTranscriptionHtml('empty-or-very-short-segments.xml');
    $this->assertEmpty($result);
  }

  /**
   * Tests the generate() method for the normal datum.
   *
   * @covers ::generate
   */
  public function testGenerateNormalDatum() : void {
    $result = $this->finalTranscriptionGenerator->generateTranscriptionHtml('normal.xml');

    // There should be no leading or trailing whitespace.
    $this->assertEquals(trim($result), $result);
    // There should be no pathologically large paragraphs.
    $maxWords = FinalTranscriptionGenerator::MAX_EXPECTED_PARAGRAPH_WORD_COUNT;
    foreach ($this->getParagraphWordCounts($result) as $wordCount) {
      $this->assertLessThanOrEqual($maxWords, $wordCount);
    }
  }

  /**
   * Tests the generate() method for the very low word count datum.
   *
   * @covers ::generate
   */
  public function testGenerateVeryShortDatum() : void {
    $result = $this->finalTranscriptionGenerator->generateTranscriptionHtml('very-short-datum.xml');
    $this->assertEquals('<p>Hi guys!</p>', $result);
  }

  /**
   * Tests the generate() method for the datum w/ very short inter-segment gaps.
   *
   * @covers ::generate
   */
  public function testGenerateVeryShortGapsDatum() : void {
    $result = $this->finalTranscriptionGenerator->generateTranscriptionHtml('very-short-segment-gaps.xml');

    // There should be no leading or trailing whitespace.
    $this->assertEquals(trim($result), $result);
    // Since there is no timestamp info to use, the result should be split into
    // paragraphs that deviate no more than a certain amount from the target
    // word count, except that the last paragraph may be shorter or longer than
    // what would otherwise be acceptable.
    $minWords = FinalTranscriptionGenerator::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT - FinalTranscriptionGenerator::SPLITTING_FLUCTUATION;
    $maxWords = FinalTranscriptionGenerator::TARGET_AVERAGE_PARAGRAPH_WORD_COUNT + FinalTranscriptionGenerator::SPLITTING_FLUCTUATION;
    foreach ($this->getParagraphWordCounts($result, TRUE) as $wordCount) {
      $this->assertGreaterThanOrEqual($minWords, $wordCount);
      $this->assertLessThanOrEqual($maxWords, $wordCount);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $mockConfigFactory = $this->getMockConfigFactory('sermon_audio.settings', [
      'transcription_bucket_name' => self::TRANSCRIPTION_BUCKET_NAME,
      'transcription_key_prefix' => self::TRANSCRIPTION_KEY_PREFIX,
      'transcription_s3_aws_region' => self::TRANSCRIPTION_S3_AWS_REGION,
    ]);

    /** @var \PHPUnit\Framework\MockObject\MockObject&\Aws\S3\S3Client */
    $mockS3Client = $this->createMock('\\Aws\\S3\\S3Client');
    $mockS3Client->method('getObject')->willReturnCallback(function (array $args) {
      if (!isset($args['Bucket'])) {
        throw new \InvalidArgumentException('Missing AWS bucket.');
      }
      if ($args['Bucket'] !== self::TRANSCRIPTION_BUCKET_NAME) {
        throw new S3Exception('Invalid bucket.', new Command('GetObject'), ['code' => 'NoSuchBucket']);
      }

      if (!isset($args['Key']) || !is_string($args['Key'])) {
        throw new \InvalidArgumentException('Missing or invalid AWS key.');
      }
      $key = $args['Key'];
      if (!str_starts_with($key, self::TRANSCRIPTION_KEY_PREFIX)) {
        throw new S3Exception('Invalid key.', new Command('GetObject'), ['code' => 'NoSuchKey']);
      }
      $prefixLength = strlen(self::TRANSCRIPTION_KEY_PREFIX);
      $subKey = strlen($key) > $prefixLength ? substr($key, $prefixLength) : '';

      if (!isset(self::$inputTranscriptionDatums[$subKey])) {
        throw new S3Exception('Invalid key.', new Command('GetObject'), ['code' => 'NoSuchKey']);
      }
      return ['Body' => self::$inputTranscriptionDatums[$subKey]];
    });
    /** @var \PHPUnit\Framework\MockObject\MockObject&\Drupal\sermon_audio\S3ClientFactory */
    $mockS3ClientFactory = $this->createMock('\\Drupal\\sermon_audio\\S3ClientFactory');
    $mockS3ClientFactory->method('getClient')->with(self::TRANSCRIPTION_S3_AWS_REGION)->willReturn($mockS3Client);

    $this->finalTranscriptionGenerator = new FinalTranscriptionGenerator($mockS3ClientFactory, $mockConfigFactory);
  }

  /**
   * Gets the word counts of the paragraphs in the given HTML.
   *
   * Fails test if expected <p>...</p> structure not found.
   *
   * @param string $html
   *   HTML. Assumed to be trimmed.
   * @param bool $skipLastParagraph
   *   Whether to skip the last paragraph when returning the word counts. This
   *   can be useful if that paragraph should be ignored, e.g. because its
   *   expected word count range may be different from the others.
   *
   * @return iterable<int>
   */
  private function getParagraphWordCounts(string $html, bool $skipLastParagraph = FALSE) : iterable {
    $paragraphsWithOpeningTag = explode('</p>', $html);
    $numParagraphs = count($paragraphsWithOpeningTag);
    if ($skipLastParagraph) $numParagraphs--;
    for ($i = 0; $i < $numParagraphs; $i++) {
      $paragraphWithOpeningTag = $paragraphsWithOpeningTag[$i];
      $this->assertStringStartsWith('<p>', $paragraphWithOpeningTag);
      $paragraph = substr($paragraphWithOpeningTag, 3);
      yield self::getNumWordsInParagraph($paragraph);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass() : void {
    self::setUpTranscriptionDatums();
  }

  /**
   * Gets the number of words in the given paragraph by counting spaces.
   *
   * @phpstan-return int<0, max>
   */
  private static function getNumWordsInParagraph(string $paragraph) : int {
    $paragraph = trim($paragraph);
    if ($paragraph === '') return 0;
    return substr_count($paragraph, ' ') + 1;
  }

}
