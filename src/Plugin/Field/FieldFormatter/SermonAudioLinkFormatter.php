<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sermon_audio\Entity\SermonAudio;
use Ranine\Helper\CastHelpers;

/**
 * Formatter for sermon audio fields that displays a link to proc. audio file.
 *
 * @FieldFormatter(
 *   id = "sermon_audio_link",
 *   label = @Translation("Audio File Link"),
 *   field_types = { "sermon_audio" },
 * )
 */
class SermonAudioLinkFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    return ['download_link_text' => [
      '#type' => 'textfield',
      '#title' => $this->t('Download link text'),
      '#default_value' => CastHelpers::stringyToString($this->getSetting('download_link_text')),
      '#description' => $this->t('Text of download link. Leave empty to use default link text.'),
      '#size' => 40,
    ]];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, mixed $langcode) : array {
    assert ($items instanceof EntityReferenceFieldItemListInterface);
    $output = [];
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $sermonAudio) {
      assert($sermonAudio instanceof SermonAudio);

      if ($sermonAudio->hasProcessedAudio()) {
        $processedAudio = $sermonAudio->getProcessedAudio(TRUE);
        if ($processedAudio === NULL) {
          $output[$delta] = [
            '#theme' => 'sermon_audio_link_broken_processed_audio',
          ];
        }
        else {
          $forcedLinkText = CastHelpers::stringyToString($this->getSetting('download_link_text'));
          if ($forcedLinkText === '') $forcedLinkText = NULL;
          $output[$delta] = [
            '#theme' => 'file_link',
            '#file' => $processedAudio,
            '#description' => $forcedLinkText,
          ];
        }
      }
      else {
        $output[$delta] = ['#theme' => 'sermon_audio_link_no_processed_audio'];
        if ($sermonAudio->hasCleaningJob()) {
          // @see \Drupal\sermon_audio\Plugin\Field\FieldFormatter\SermonAudioPlayerFormatter::viewElements
          $output[$delta]['#cache']['max-age'] = 0;
        }
      }

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
    return ['download_link_text' => 'Download Sermon Audio'] + parent::defaultSettings();
  }

}
