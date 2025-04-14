<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Base analyzable field processor plugin class.
 */
abstract class AnalyzableFieldProcessorPluginBase extends PluginBase implements AnalyzableFieldProcessorPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'analyzable_field_processor';
  }

  /**
   * {@inheritdoc}
   */
  public function filterFiles(FieldItemListInterface $field, array $supported_file_types): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function supports(FieldDefinitionInterface $field_definition): bool {
    $type = $field_definition->getType();
    $definition = $this->getPluginDefinition();
    return empty($definition['types']) || in_array($type, $definition['types']);
  }

}
