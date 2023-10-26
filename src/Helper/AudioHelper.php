<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Helper methods dealing with sermon audio.
 *
 * @static
 */
final class AudioHelper {

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * If necessary and possible, refreshes processed audio for all translations.
   *
   * For a given translation, processed audio is only refreshed if
   * self::isProcessedAudioRefreshable() returns TRUE.
   *
   * @param \Drupal\sermon_audio\Entity\SermonAudio $audio
   *   Sermon audio entity whose processed audio should be refreshed.
   *
   * @return bool
   *   FALSE if $audio was definitely not modified during this method; TRUE if
   *   $audio may have been modified and should therefore be saved.
   */
  public static function refreshProcessedAudioAllTranslations(SermonAudio $audio) : bool {
    $requiresSave = FALSE;
    foreach ($audio->iterateTranslations() as $translation) {
      if (!self::isProcessedAudioRefreshable($translation)) continue;
      if ($translation->refreshProcessedAudio()) $requiresSave = TRUE;
    }

    return $requiresSave;
  }

  /**
   * Tells if it is reasonable to refresh the processed audio for translation.
   *
   * Processed audio is considered "refreshable" if and only if:
   * 1) The translation has no existing processed audio.
   * 2) Audio processing was initiated for the translation.
   * 3) The translation's unprocessed audio exists.
   *
   * @param \Drupal\sermon_audio\Entity\SermonAudio $audioTranslation
   *   Sermon audio entity translation whose "processed audio refreshability"
   *   should be determined.
   */
  public static function isProcessedAudioRefreshable(SermonAudio $audioTranslation) : bool {
    if ($audioTranslation->hasProcessedAudio()) return FALSE;
    if (!$audioTranslation->wasAudioProcessingInitiated()) return FALSE;
    // One shouldn't attempt to refresh the processed audio if the unprocessed
    // audio does not exist.
    if ($audioTranslation->getUnprocessedAudio(TRUE) === NULL) return FALSE;

    return TRUE;
  }

}
