<?php

declare(strict_types = 1);

namespace Drupal\sermon_audio;

use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Sermon audio storage schema.
 *
 * Used to implement custom indexes on base fields.
 */
class SermonAudioStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, mixed $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);

    if ($table_name == 'sermon_audio_field_data') {
      $fieldName = $storage_definition->getName();
      switch ($fieldName) {
        case 'cleaning_job_id':
        case 'transcription_job_id':
          $this->addSharedTableFieldIndex($storage_definition, $schema);
      }
    }

    return $schema;
  }

}
