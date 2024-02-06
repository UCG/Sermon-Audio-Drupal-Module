<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio;

use Ranine\Helper\ThrowHelpers;

/**
 * Represents a piece of a sermon transcription.
 */
class TranscriptionSegment {

  private readonly float $end;
  private readonly float $start;
  /** @phpstan-var non-empty-string */
  private readonly string $text;

  /**
   * Creates a new transcription segment.
   *
   * @param float $start
   *   Start time in seconds.
   * @param float $end
   *   End time in seconds.
   * @param string $text
   *   Transcription text.
   * @phpstan-param non-empty-string $text
   *
   * @throws \InvalidArgumentException
   *   Thrown if $text is empty.
   * @throws \InvalidArgumentException
   *   Thrown if $start is less than zero.
   * @throws \InvalidArgumentException
   *   Thrown if $end is less than $start.
   */
  public function __construct(float $start, float $end, string $text) {
    ThrowHelpers::throwIfEmptyString($text, 'text');
    ThrowHelpers::throwIfLessThanZero($start, 'start');
    if ($end < $start) {
      throw new \InvalidArgumentException('$end is less than $start.');
    }

    $this->start = $start;
    $this->end = $end;
    $this->text = $text;
  }

  /**
   * Gets the end time in seconds.
   */
  public function getEnd() : float {
    return $this->end;
  }

  /**
   * Gets the start time in seconds.
   */
  public function getStart() : float {
    return $this->start;
  }

  /**
   * Gets the transcription text.
   *
   * @phpstan-return non-empty-string
   */
  public function getText() : string {
    return $this->text;
  }

}
