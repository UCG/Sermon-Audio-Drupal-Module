<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Helper methods dealing with refreshing sermon audio or transcription data.
 *
 * @static
 */
final class RefreshHelpers {

  /**
   * Empty private constructor to ensure no one instantiates this class.
   */
  private function __construct() {
  }

  /**
   * Where there is a cleaning job, refreshes proc. audio for all translations.
   *
   * NOTE: For exception information, see SermonAudio::refreshProcessedAudio().
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
      if ($translation->hasCleaningJob()) {
        if ($translation->refreshProcessedAudio()) $requiresSave = TRUE;
      }
    }

    return $requiresSave;
  }

  /**
   * Where there is a trans. job, refreshes proc. audio for all translations.
   *
   * NOTE: For exception information, see SermonAudio::refreshTranscription().
   *
   * @param \Drupal\sermon_audio\Entity\SermonAudio $audio
   *   Sermon audio entity whose transcription sub-key should be refreshed.
   * @param ?\Drupal\sermon_audio\Entity\SermonAudio[] $translationsWithUpdatedTranscriptionSubKey
   *   (output) Array of entities whose transcription sub-key was updated with
   *   a value from a transcription job. Pass NULL to ignore.
   *
   * @return bool
   *   FALSE if $audio was definitely not modified during this method; TRUE if
   *   $audio may have been modified and should therefore be saved.
   */
  public static function refreshTranscriptionAllTranslations(SermonAudio $audio, ?array &$translationsWithUpdatedTranscriptionSubKey = NULL) : bool {
    $requiresSave = FALSE;
    foreach ($audio->iterateTranslations() as $translation) {
      if ($translation->hasTranscriptionJob()) {
        if ($translation->refreshTranscription()) {
          $requiresSave = TRUE;
          if ($translationsWithUpdatedTranscriptionSubKey !== NULL) {
            // If the entity no longer has an active transcription job, and the
            // job did not fail, then we know the transcription sub-key was
            // updated with data from the previously active job.
            if (!$translation->hasTranscriptionJob() && !$translation->didTranscriptionFail()) {
              $translationsWithUpdatedTranscriptionSubKey[] = $translation;
            }
          }
        }
      }
    }

    return $requiresSave;
  }

}
