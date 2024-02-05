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
    // @todo Do we need anything in hook_update_N() to make this take effect if
    // the module is already installed? See https://api.drupal.org/api/drupal/core%21modules%21node%21node.install/function/node_update_8002/8.9.x.

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
