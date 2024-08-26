<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Helper;

use Drupal\sermon_audio\Entity\SermonAudio;
use Drupal\sermon_audio\Event\AudioSpontaneouslyUpdatedEvent;
use Drupal\sermon_audio\Event\SermonAudioEvents;
use Drupal\sermon_audio\Event\TranscriptionSpontaneouslyUpdatedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
   * @param false|null|callable (\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher) : void $dispatching
   *   (output) If not FALSE, when returned this can be used to invoke the
   *   "processed audio auto-updated" event with the provided dispatcher.
   *
   * @return bool
   *   FALSE if $audio was definitely not modified during this method; TRUE if
   *   $audio may have been modified and should therefore be saved.
   */
  public static function refreshProcessedAudioAllTranslations(SermonAudio $audio, callable|null|false &$dispatching = FALSE) : bool {
    $requiresSave = FALSE;
    /** @var \Drupal\sermon_audio\Entity\SermonAudio[] */
    $translationsWithUpdatedProcessedAudio = [];
    foreach ($audio->iterateTranslations() as $translation) {
      if ($translation->hasCleaningJob()) {
        $audioUpdated = FALSE;
        if ($translation->refreshProcessedAudio($audioUpdated)) {
          $requiresSave = TRUE;
          if ($dispatching !== FALSE && $audioUpdated) {
            $translationsWithUpdatedProcessedAudio[] = $translation;
          }
        }
      }
    }

    if ($dispatching !== FALSE) {
      if ($requiresSave) {
        $dispatching = function (EventDispatcherInterface $dispatcher) use ($translationsWithUpdatedProcessedAudio) : void {
          foreach ($translationsWithUpdatedProcessedAudio as $translation) {
            $dispatcher->dispatch(new AudioSpontaneouslyUpdatedEvent($translation), SermonAudioEvents::AUDIO_SPONTANEOUSLY_UPDATED);
          }
        };
      }
      else $dispatching = function ($d) : void {};
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
   * @param false|null|callable (\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher) : void $dispatching
   *   (output) If not FALSE, when returned this can be used to invoke the
   *   "transcription auto-updated" event with the provided dispatcher.
   *
   * @return bool
   *   FALSE if $audio was definitely not modified during this method; TRUE if
   *   $audio may have been modified and should therefore be saved.
   */
  public static function refreshTranscriptionAllTranslations(SermonAudio $audio, callable|null|false &$dispatching = FALSE) : bool {
    $requiresSave = FALSE;
    /** @var \Drupal\sermon_audio\Entity\SermonAudio[] */
    $translationsWithUpdatedTranscriptionSubKey = [];
    foreach ($audio->iterateTranslations() as $translation) {
      if ($translation->hasTranscriptionJob()) {
        $subKeyUpdated = FALSE;
        if ($translation->refreshTranscription($subKeyUpdated)) {
          $requiresSave = TRUE;
          if ($dispatching !== FALSE && $subKeyUpdated) {
            $translationsWithUpdatedTranscriptionSubKey[] = $translation;
          }
        }
      }
    }

    if ($dispatching !== FALSE) {
      if ($requiresSave) {
        $dispatching = function (EventDispatcherInterface $dispatcher) use ($translationsWithUpdatedTranscriptionSubKey) : void {
          foreach ($translationsWithUpdatedTranscriptionSubKey as $translation) {
            $dispatcher->dispatch(new TranscriptionSpontaneouslyUpdatedEvent($translation), SermonAudioEvents::TRANSCRIPTION_SPONTANEOUSLY_UPDATED);
          }
        };
      }
      else $dispatching = function ($d) : void {};
    }
    return $requiresSave;
  }

}
