<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Fired when processed audio for sermon audio is spontaneously changed.
 *
 * A "spontaneous change" occurs when the processed audio is changed because it
 * was updated in a cron job, or because it was updated by an announcement
 * controller. It does not include the case when the audio is changed in
 * SermonAudio::postLoad().
 */
class AudioSpontaneouslyUpdatedEvent extends Event {

  /**
   * Translation whose processed audio was updated.
   */
  private readonly SermonAudio $translation;

  /**
   * Creates a new audio auto-updated event.
   *
   * @param \Drupal\sermon_audio\Entity\SermonAudio $translation
   *   Translation whose processed audio was updated.
   */
  public function __construct(SermonAudio $translation) {
    $this->translation = $translation;
  }

  /**
   * Gets the sermon audio translation whose processed audio was updated.
   */
  public function getTranslation() : SermonAudio {
    return $this->translation;
  }

}
