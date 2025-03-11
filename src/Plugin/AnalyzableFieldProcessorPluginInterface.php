<?php

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for the ocha content analyzable field processor plugins.
 */
interface AnalyzableFieldProcessorPluginInterface {

  /**
   * Convert field value to a string.
   *
   * @param string $placeholder
   *   Placeholder name for the field.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The entity field to process.
   *
   * @return string
   *   The processed field value as a string.
   */
  public function toString(string $placeholder, FieldItemListInterface $field): string;

  /**
   * Convert field value to a list of files.
   *
   * @param string $placeholder
   *   Placeholder name for the field.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The entity field to process.
   *
   * @return array
   *   The processed field value as a list of files. Each file is an
   *   associative array with the following properties:
   *   - mimetype (string): the mime type of the file.
   *   - id (string): optional document ID, for example for reference in the
   *     prompt.
   *   - data (string): optional content of the file. If not defined, the `uri`
   *     property should be set.
   *   - uri (string): optional URI of the file. If not defined, the `data`
   *     property should be set.
   *   - base64 (bool): optional flag indicating if the data is already base64
   *     encoded.
   */
  public function toFiles(string $placeholder, FieldItemListInterface $field): array;

  /**
   * Check if the plugin supports the given field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   *
   * @return bool
   *   TRUE if the field is supported.
   */
  public function supports(FieldDefinitionInterface $field_definition): bool;

}
