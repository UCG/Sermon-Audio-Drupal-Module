<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sermon_audio\Helper\SettingsHelpers;
use Ranine\Helper\CastHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to configure module settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array {
    $configuration = $this->config('sermon_audio.settings');

    $form['aws_credentials_file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Credentials File Path'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('aws_credentials_file_path')),
      '#description' => $this->t('The full path to the JSON file where AWS credentials are stored, if applicable. If left empty, the AWS SDK will use its built-in credential discovery method (beginning by attempting to extract credentials from the environment).'),
    ];
    $form['site_token_file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site Token File Path'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('site_token_file_path')),
      '#description' => $this->t('The full path to text file where site token (controlling access to announcement routes) is stored.'),
      '#required' => TRUE,
    ];
    $form['audio_s3_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS S3 Audio Storage Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('audio_s3_aws_region')),
      '#description' => $this->t('The AWS region in which S3 audio (processed and unprocessed) files reside.'),
      '#required' => TRUE,
    ];
    $form['audio_bucket_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS S3 Audio Storage Bucket Name'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('audio_bucket_name')),
      '#description' => $this->t('The name of the AWS S3 bucket where processed and unprocessed audio files are stored.'),
      '#required' => TRUE,
    ];
    $form['unprocessed_audio_uri_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unprocessed Audio File URI Prefix'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('unprocessed_audio_uri_prefix')),
      '#description' => $this->t('The prefix, including the schema, for unprocessed sermon audio files.'),
      '#required' => TRUE,
    ];
    $form['processed_audio_uri_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Processed Audio File URI Prefix'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('processed_audio_uri_prefix')),
      '#description' => $this->t('The prefix, including the schema, for processed sermon audio files. This is ignored in debug mode.'),
      '#required' => TRUE,
    ];
    $form['processed_audio_key_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Processed Audio File S3 Key Prefix'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('processed_audio_key_prefix')),
      '#description' => $this->t('The prefix, including any trailing slash, for processed sermon audio S3 keys.'),
      '#required' => TRUE,
    ];
    $form['job_submission_endpoint_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Job Submission Endpoint AWS Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('job_submission_endpoint_aws_region')),
      '#description' => $this->t('AWS region where processing job submission endpoint resides.'),
      '#required' => TRUE,
    ];
    $form['job_submission_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Job Submission Endpoint'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('job_submission_endpoint')),
      '#description' => $this->t('Audio processing job submission AWS HTTP endpoint.'),
      '#required' => TRUE,
    ];
    $form['transcription_job_results_endpoint_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Job Results Endpoint AWS Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_job_results_endpoint_aws_region')),
      '#description' => $this->t('AWS region where transcription job results endpoint resides.'),
      '#required' => TRUE,
    ];
    $form['transcription_job_results_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Job Results Endpoint'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_job_results_endpoint')),
      '#description' => $this->t('Transcription job results AWS HTTP endpoint.'),
      '#required' => TRUE,
    ];
    $form['transcription_job_submission_endpoint_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Job Submission Endpoint AWS Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_job_submission_endpoint_aws_region')),
      '#description' => $this->t('AWS region where transcription-only job submission endpoint resides.'),
      '#required' => TRUE,
    ];
    $form['transcription_job_submission_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Job Submission Endpoint'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_job_submission_endpoint')),
      '#description' => $this->t('Transcription-only job submission AWS HTTP endpoint.'),
      '#required' => TRUE,
    ];
    $form['transcription_s3_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription S3 AWS Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_s3_aws_region')),
      '#description' => $this->t('AWS region where audio transcription S3 bucket resides.'),
      '#required' => TRUE,
    ];
    $form['transcription_bucket_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Bucket Name'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_bucket_name')),
      '#description' => $this->t('Name of AWS S3 bucket where output transcription XML files are stored.'),
      '#required' => TRUE,
    ];
    $form['transcription_key_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transcription Key Prefix'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('transcription_key_prefix')),
      '#description' => $this->t('S3 prefix of keys for output transcription XML files.'),
      '#required' => TRUE,
    ];
    $form['cleaning_job_results_endpoint_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cleaning Job Results Endpoint AWS Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('cleaning_job_results_endpoint_aws_region')),
      '#description' => $this->t('AWS region where audio cleaning job results endpoint resides.'),
      '#required' => TRUE,
    ];
    $form['cleaning_job_results_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cleaning Job Results Endpoint'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('cleaning_job_results_endpoint')),
      '#description' => $this->t('Audio cleaning job results AWS HTTP endpoint.'),
      '#required' => TRUE,
    ];
    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#default_value' => (bool) $configuration->get('debug_mode'),
      '#description' => $this->t(<<<'EOS'
Whether to enable "debug mode": when enabled, the processed audio field will
simply be made the same as the unprocessed audio field when
\Drupal\sermon_audio\Entity\SermonAudio::refreshProcessedAudio() is called.
No actual audio processing or AWS API calls take place if this mode is enabled.
If you use this mode with the idea of not having to access S3, you will want to
set the unprocessed audio URI prefix to the non-S3 location you use for
debugging.
EOS
      ),
    ];
    $form['connect_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Connect Timeout'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => SettingsHelpers::getConnectionTimeout($configuration),
      '#description' => $this->t('Timeout in seconds when attempting to connect to a server while making AWS calls.'),
      '#required' => FALSE,
    ];
    $form['endpoint_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Endpoint Timeout'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => SettingsHelpers::getEndpointTimeout($configuration),
      '#description' => $this->t('Total timeout in seconds when making requests to custom AWS HTTP endpoints.'),
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'sermon_audio_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() : array {
    return ['sermon_audio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {
    $configuration = $this->config('sermon_audio.settings');

    $configuration->set('aws_credentials_file_path', CastHelpers::stringyToString($form_state->getValue('aws_credentials_file_path')));
    $configuration->set('site_token_file_path', CastHelpers::stringyToString($form_state->getValue('site_token_file_path')));
    $configuration->set('audio_s3_aws_region', CastHelpers::stringyToString($form_state->getValue('audio_s3_aws_region')));
    $configuration->set('audio_bucket_name', CastHelpers::stringyToString($form_state->getValue('audio_bucket_name')));
    $configuration->set('unprocessed_audio_uri_prefix', CastHelpers::stringyToString($form_state->getValue('unprocessed_audio_uri_prefix')));
    $configuration->set('processed_audio_uri_prefix', CastHelpers::stringyToString($form_state->getValue('processed_audio_uri_prefix')));
    $configuration->set('processed_audio_key_prefix', CastHelpers::stringyToString($form_state->getValue('processed_audio_key_prefix')));
    $configuration->set('job_submission_endpoint_aws_region', CastHelpers::stringyToString($form_state->getValue('job_submission_endpoint_aws_region')));
    $configuration->set('job_submission_endpoint', CastHelpers::stringyToString($form_state->getValue('job_submission_endpoint')));
    $configuration->set('transcription_job_results_endpoint_aws_region', CastHelpers::stringyToString($form_state->getValue('transcription_job_results_endpoint_aws_region')));
    $configuration->set('transcription_job_results_endpoint', CastHelpers::stringyToString($form_state->getValue('transcription_job_results_endpoint')));
    $configuration->set('transcription_job_submission_endpoint_aws_region', CastHelpers::stringyToString($form_state->getValue('transcription_job_submission_endpoint_aws_region')));
    $configuration->set('transcription_job_submission_endpoint', CastHelpers::stringyToString($form_state->getValue('transcription_job_submission_endpoint')));
    $configuration->set('transcription_s3_aws_region', CastHelpers::stringyToString($form_state->getValue('transcription_s3_aws_region')));
    $configuration->set('transcription_bucket_name', CastHelpers::stringyToString($form_state->getValue('transcription_bucket_name')));
    $configuration->set('transcription_key_prefix', CastHelpers::stringyToString($form_state->getValue('transcription_key_prefix')));
    $configuration->set('cleaning_job_results_endpoint_aws_region', CastHelpers::stringyToString($form_state->getValue('cleaning_job_results_endpoint_aws_region')));
    $configuration->set('cleaning_job_results_endpoint', CastHelpers::stringyToString($form_state->getValue('cleaning_job_results_endpoint')));
    $configuration->set('debug_mode', (bool) $form_state->getValue('debug_mode'));

    $connectTimeout = CastHelpers::intyToInt($form_state->getValue('connect_timeout'));
    $endpointTimeout = CastHelpers::intyToInt($form_state->getValue('endpoint_timeout'));
    $configuration->set('connect_timeout', $connectTimeout > 0 ? $connectTimeout : NULL);
    $configuration->set('endpoint_timeout', $endpointTimeout > 0 ? $endpointTimeout : NULL);

    $configuration->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : static {
    $configFactory = $container->get('config.factory');
    assert($configFactory instanceof ConfigFactoryInterface);
    /** @phpstan-ignore-next-line */
    return new static($configFactory);
  }

}
