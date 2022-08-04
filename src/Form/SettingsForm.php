<?php

declare (strict_types = 1);

namespace Drupal\sermon_audio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $configuration = $this->config('sermon_audio.settings');

    $form['aws_credentials_file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Credentials File Path'),
      '#default_value' => (string) $configuration->get('aws_credentials_file_path'),
      '#description' => $this->t('The full path to the JSON file where AWS credentials are stored, if applicable. If left empty, the AWS SDK will use its built-in credential discovery method (beginning by attempting to extract credentials from the environment).'),
    ];
    $form['audio_s3_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS S3 Audio Storage Region'),
      '#default_value' => (string) $configuration->get('audio_s3_aws_region'),
      '#description' => $this->t('The AWS region in which S3 audio (processed and unprocessed) files reside.'),
      '#required' => TRUE,
    ];
    $form['jobs_db_aws_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS DynamoDB Audio Processing Jobs Table Region'),
      '#default_value' => (string) $configuration->get('jobs_db_aws_region'),
      '#description' => $this->t('The AWS region in which the DynamoDB audio processing jobs table resides.'),
      '#required' => TRUE,
    ];
    $form['jobs_table_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS DynamoDB Audio Processing Jobs Table Name'),
      '#default_value' => (string) $configuration->get('jobs_table_name'),
      '#description' => $this->t('The name of the AWS DynamoDB audio processing jobs table.'),
      '#required' => TRUE,
    ];
    $form['audio_bucket_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS S3 Audio Storage Bucket Name'),
      '#default_value' => (string) $configuration->get('audio_bucket_name'),
      '#description' => $this->t('The name of the AWS S3 bucket where processed and unprocessed audio files are stored.'),
      '#required' => TRUE,
    ];
    $form['unprocessed_audio_uri_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unprocessed Audio File URI Prefix'),
      '#default_value' => (string) $configuration->get('unprocessed_audio_uri_prefix'),
      '#description' => $this->t('The prefix, including the schema, for unprocessed sermon audio files.'),
      '#required' => TRUE,
    ];
    $form['processed_audio_uri_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Processed Audio File URI Prefix'),
      '#default_value' => (string) $configuration->get('processed_audio_uri_prefix'),
      '#description' => $this->t('The prefix, including the schema, for processed sermon audio files.'),
      '#required' => TRUE,
    ];
    $form['processed_audio_key_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Processed Audio File S3 Key Prefix'),
      '#default_value' => (string) $configuration->get('processed_audio_key_prefix'),
      '#description' => $this->t('The prefix, including any trailing slash, for processed sermon audio S3 keys.'),
      '#required' => TRUE,
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

    $configuration->set('aws_credentials_file_path', (string) $form_state->getValue('aws_credentials_file_path'));
    $configuration->set('audio_s3_aws_region', (string) $form_state->getValue('audio_s3_aws_region'));
    $configuration->set('jobs_db_aws_region', (string) $form_state->getValue('jobs_db_aws_region'));
    $configuration->set('jobs_table_name', (string) $form_state->getValue('jobs_table_name'));
    $configuration->set('audio_bucket_name', (string) $form_state->getValue('audio_bucket_name'));
    $configuration->set('unprocessed_audio_uri_prefix', (string) $form_state->getValue('unprocessed_audio_uri_prefix'));
    $configuration->set('processed_audio_uri_prefix', (string) $form_state->getValue('processed_audio_uri_prefix'));
    $configuration->set('processed_audio_key_prefix', (string) $form_state->getValue('processed_audio_key_prefix'));

    $configuration->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : static {
    return new static($container->get('config.factory'));
  }

}
