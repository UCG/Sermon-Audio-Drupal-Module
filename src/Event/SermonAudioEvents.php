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
   * Name of event fired when audio transcription is spontaneously updated.
   */
  public const TRANSCRIPTION_AUTO_UPDATED = 'sermon_audio.transcription_auto_updated';

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

}
