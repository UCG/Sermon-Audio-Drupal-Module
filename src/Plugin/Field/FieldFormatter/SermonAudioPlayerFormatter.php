<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sermon_audio\Entity\SermonAudio;

/**
 * Formatter for sermon audio fields that displays proc. audio with a player.
 *
 * @FieldFormatter(
 *   id = "sermon_audio_player",
 *   label = @Translation("Audio Player"),
 *   field_types = { "sermon_audio" },
 * )
 */
class SermonAudioPlayerFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    // Adapted from @see \Drupal\file\Plugin\Field\FieldFormatter\FileMediaFormatterBase::settingsForm()
    return [
      'controls' => [
        '#title' => $this->t('Show playback controls'),
        '#type' => 'checkbox',
        '#default_value' => (bool) $this->getSetting('controls'),
      ],
      'autoplay' => [
        '#title' => $this->t('Autoplay'),
        '#type' => 'checkbox',
        '#default_value' => (bool) $this->getSetting('autoplay'),
      ],
      'loop' => [
        '#title' => $this->t('Loop'),
        '#type' => 'checkbox',
        '#default_value' => (bool) $this->getSetting('loop'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $output = [];
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $sermonAudio) {
      assert($sermonAudio instanceof SermonAudio);

      if ($sermonAudio->hasProcessedAudio()) {
        // Pre-load the processed audio entity, if possible, so that it gets
        // loaded into the cache and we don't get a broken-reference race
        // condition later (which wouldn't be a big deal, but anyway...).
        if ($sermonAudio->getProcessedAudio(TRUE) === NULL) {
          $output[$delta] = [
            '#theme' => 'sermon_audio_player_broken_processed_audio',
          ];
        }
        else {
          // Force the use of the MP4 audio file formatter (with no label).
          $output[$delta] = $sermonAudio->get('processed_audio')->view([
            'type' => 'mp4_enabled_audio_file',
            'settings' => [
              'controls' => (bool) $this->getSetting('controls'),
              'autoplay' => (bool) $this->getSetting('autoplay'),
              'loop' => (bool) $this->getSetting('loop'),
            ],
            'label' => 'hidden',
            'weight' => 0,
          ]);
        }
      }
      else {
        $wasProcessingInitiated = $sermonAudio->wasAudioProcessingInitiated();
        $output[$delta] = [
          '#theme' => 'sermon_audio_player_no_processed_audio',
          '#was_processing_initiated' => $wasProcessingInitiated,
        ];
        if ($wasProcessingInitiated) {
          // Since audio processing has been initiated but we have no processed
          // audio yet, we don't want to cache the output, as processing could
          // be finished at any time.
          $output[$delta]['#cache']['max-age'] = 0;
        }
      }

      // Attach the sermon audio entity as a dependency with respect to caching.
      if (isset($output[$delta]['#cache']['tags'])) {
        $cacheTags =& $output[$delta]['#cache']['tags'];
        $cacheTags = Cache::mergeTags($cacheTags, $sermonAudio->getCacheTags());
      }
      else {
        $output[$delta]['#cache']['tags'] = $sermonAudio->getCacheTags();
      }
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() : array {
    return [
      'controls' => TRUE,
      'autoplay' => FALSE,
      'loop' => FALSE,
    ] + parent::defaultSettings();
  }

}
