<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Event;

/**
 * Static class containing event names for the sermon audio module.
 *
 * @static
 */
final class SermonAudioEvents {

  /**
   * Name of event fired when processed audio is spontaneously updated.
   *
   * @var string
   */
  public const AUDIO_SPONTANEOUSLY_UPDATED = 'sermon_audio.audio_spontaneously_updated';

  /**
   * Name of event fired when transcription sub-key is spontaneously updated.
   *
   * @var string
   */
  public const TRANSCRIPTION_SPONTANEOUSLY_UPDATED = 'sermon_audio.transcription_spontaneously_updated';

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

}
