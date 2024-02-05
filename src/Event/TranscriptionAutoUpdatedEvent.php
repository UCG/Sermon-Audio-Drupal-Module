<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Fired when transcription sub-key for sermon audio is spontaneously changed.
 */
class TranscriptionAutoUpdatedEvent extends Event {

  /**
   * Translation whose transcription sub-key was updated.
   */
  private readonly SermonAudio $translation;

  /**
   * Creates a new transcription auto-updated event.
   *
   * @param \Drupal\sermon_audio\Entity\SermonAudio $translation
   *   Translations whose transcription sub-key was updated.
   */
  public function __construct(SermonAudio $translation) {
    $this->translation = $translation;
  }

  /**
   * Gets the sermon audio translation whose transcription sub-key was updated.
   */
  public function getTranslation() : SermonAudio {
    return $this->translation;
  }

}
