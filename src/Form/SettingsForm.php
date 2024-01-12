<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sermon_audio\Helper\CastHelpers;
use Drupal\sermon_audio\Helper\SettingsHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to configure module settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Creates a new settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    parent::__construct($configFactory);
  }

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
    $form['audio_s3_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS S3 Audio Storage Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('audio_s3_aws_region')),
      '#description' => $this->t('The AWS region in which S3 audio (processed and unprocessed) files reside.'),
      '#required' => TRUE,
    ];
    $form['jobs_db_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS DynamoDB Audio Processing Jobs Table Region'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('jobs_db_aws_region')),
      '#description' => $this->t('The AWS region in which the DynamoDB audio processing jobs table resides.'),
      '#required' => TRUE,
    ];
    $form['jobs_table_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS DynamoDB Audio Processing Jobs Table Name'),
      '#default_value' => CastHelpers::stringyToString($configuration->get('jobs_table_name')),
      '#description' => $this->t('The name of the AWS DynamoDB audio processing jobs table.'),
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
    $form['dynamodb_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('DynamoDB Timeout'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => SettingsHelpers::getDynamoDbTimeout($configuration),
      '#description' => $this->t('Timeout in seconds when making AWS DynamoDB requests (whose responses should return relatively quickly).'),
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
    $configuration->set('audio_s3_aws_region', CastHelpers::stringyToString($form_state->getValue('audio_s3_aws_region')));
    $configuration->set('jobs_db_aws_region', CastHelpers::stringyToString($form_state->getValue('jobs_db_aws_region')));
    $configuration->set('jobs_table_name', CastHelpers::stringyToString($form_state->getValue('jobs_table_name')));
    $configuration->set('audio_bucket_name', CastHelpers::stringyToString($form_state->getValue('audio_bucket_name')));
    $configuration->set('unprocessed_audio_uri_prefix', CastHelpers::stringyToString($form_state->getValue('unprocessed_audio_uri_prefix')));
    $configuration->set('processed_audio_uri_prefix', CastHelpers::stringyToString($form_state->getValue('processed_audio_uri_prefix')));
    $configuration->set('processed_audio_key_prefix', CastHelpers::stringyToString($form_state->getValue('processed_audio_key_prefix')));
    $configuration->set('debug_mode', (bool) $form_state->getValue('debug_mode'));

    $connectTimeout = CastHelpers::intyToInt($form_state->getValue('connect_timeout'));
    $dynamoDbTimeout = CastHelpers::intyToInt($form_state->getValue('dynamodb_timeout'));
    $configuration->set('connect_timeout', $connectTimeout > 0 ? $connectTimeout : NULL);
    $configuration->set('dynamodb_timeout', $dynamoDbTimeout > 0 ? $dynamoDbTimeout : NULL);

    $configuration->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : self {
    $configFactory = $container->get('config.factory');
    assert($configFactory instanceof ConfigFactoryInterface);
    return new self($configFactory);
  }

}
