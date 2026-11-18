<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Service\ClassificationSimulationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base classifier plugin class.
 */
abstract class ClassifierPluginBase extends PluginBase implements ClassifierPluginInterface {

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface $analyzableFieldProcessorPluginManager
   *   The analyzable field processor plugin manager.
   * @param \Drupal\ocha_content_classification\Service\ClassificationSimulationContext $classificationSimulationContext
   *   Simulation context (dry-run classification without invoking post hooks).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
    protected ModuleHandlerInterface $moduleHandler,
    protected AnalyzableFieldProcessorPluginManagerInterface $analyzableFieldProcessorPluginManager,
    protected ClassificationSimulationContext $classificationSimulationContext,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('plugin.manager.ocha_content_classification.analyzable_field_processor'),
      $container->get('ocha_content_classification.classification_simulation_context'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'classifier';
  }

  /**
   * Update the entity after the classification has completed.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param \Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface $classifier
   *   The classifier plugin.
   * @param array $classified_fields
   *   The list of fields that the classifier handled, keyed by type:
   *   classifiable or fillable with the list of fields keyed by field names
   *   as values.
   * @param array $data
   *   The raw data from the classifier. This depends on the classifier.
   *
   * @return array
   *   The list of entity fields that were updated.
   */
  protected function updateEntity(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ClassifierPluginInterface $classifier,
    array $classified_fields,
    array $data = [],
  ): array {
    // Allow other modules to alter the list of fields before updating the
    // entity.
    $classified_fields_context = [
      'entity' => $entity,
      'classifier' => $this,
      'data' => $data,
    ];
    $this->moduleHandler->alter(
      'ocha_content_classification_classified_fields',
      $classified_fields,
      $workflow,
      $classified_fields_context,
    );

    // Update the entity.
    $entity_updated_fields = [];
    foreach ($classified_fields as $type => $field_list) {
      foreach ($field_list as $field_name => $values) {
        // Classifiable fields are taxonomy term fields, we can simply pass
        // the new list of term IDs to update the field.
        if ($type === 'classifiable') {
          $entity->set($field_name, $values);
        }
        // For fillable fields, we only want to update the enabled properties.
        elseif ($type === 'fillable') {
          // Retrieve the first field item or create a new one.
          $field_item = $entity->get($field_name)->first() ??
            $entity->get($field_name)->appendItem()->applyDefaultValue(FALSE);
          // @todo find a better way than this workaround for the body field...
          if ($field_name === 'body' && empty($field_item->value) && !isset($values['value'])) {
            $field_item->set('value', '');
          }
          foreach ($values as $property => $value) {
            $field_item->set($property, $value);
          }
        }
        $entity_updated_fields[] = $field_name;
      }
    }

    // Allow other modules to do something with the classification data after
    // the entity has been updated. Skip during simulation so hooks cannot
    // persist side effects to storage.
    $hook_entity_updated_fields = [];
    if (!$this->classificationSimulationContext->isSimulating()) {
      $hook_entity_updated_fields = $this->moduleHandler->invokeAll(
        'ocha_content_classification_post_classify_entity',
        [$entity, $workflow, $this, $entity_updated_fields, $data]
      ) ?? [];
    }

    $entity_updated_fields = array_values(array_unique(array_merge(
      $entity_updated_fields,
      $hook_entity_updated_fields,
    )));

    return $entity_updated_fields;
  }

  /**
   * Resolve the max length for a fillable field property from storage settings.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param string $property
   *   The field property name (e.g. "value" for title).
   *
   * @return int|null
   *   Positive max length, or NULL when the field has no length constraint.
   */
  protected function getFillablePropertyMaxLength(FieldDefinitionInterface $field_definition, string $property): ?int {
    // Storage max_length applies to string/text value columns (e.g. title →
    // value). $property is retained for a consistent call-site API.
    $max_length = $field_definition->getFieldStorageDefinition()->getSetting('max_length');
    if (!is_numeric($max_length)) {
      return NULL;
    }
    $max_length = (int) $max_length;
    return $max_length > 0 ? $max_length : NULL;
  }

  /**
   * Truncate a fillable string to the field property max length when needed.
   *
   * Oversized values are truncated word-safe with an ellipsis and a warning is
   * logged. This must not throw: a long source title should not fail/retry
   * classification of other fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param string $field_name
   *   The field name.
   * @param string $property
   *   The field property name.
   * @param string $value
   *   The sanitized fillable value.
   *
   * @return string
   *   The value, truncated when it exceeds the storage max length.
   */
  protected function enforceFillablePropertyMaxLength(
    ContentEntityInterface $entity,
    string $field_name,
    string $property,
    string $value,
  ): string {
    $field_definition = $entity->get($field_name)->getFieldDefinition();
    $max_length = $this->getFillablePropertyMaxLength($field_definition, $property);
    if ($max_length === NULL) {
      return $value;
    }

    $original_length = mb_strlen($value);
    if ($original_length <= $max_length) {
      return $value;
    }

    $truncated = Unicode::truncate($value, $max_length, TRUE, TRUE);
    $this->getLogger()->warning('Truncated fillable value for @entity_type @entity_id field @field property @property from @original_length to max length @max_length.', [
      '@entity_type' => $entity->getEntityTypeId(),
      '@entity_id' => $entity->id() ?? $entity->uuid() ?? 'unknown',
      '@field' => $field_name,
      '@property' => $property,
      '@original_length' => (string) $original_length,
      '@max_length' => (string) $max_length,
    ]);

    return $truncated;
  }

  /**
   * Prepare an entity for classification.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Prepared entity.
   */
  protected function prepareEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): ContentEntityInterface {
    $prepared_entity = clone $entity;

    // Allow other modules to alter the entity to prepare.
    $context = [
      'entity' => $entity,
      'classifier' => $this,
    ];
    $this->moduleHandler->alter(
      'ocha_content_classification_prepare_entity',
      $prepared_entity,
      $workflow,
      $context,
    );

    return $prepared_entity;
  }

  /**
   * Check if that the entity has the data necessary for the classification.
   *
   * Note: this is called before the actual classification.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return bool
   *   TRUE if the entity can be processed.
   *
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationFailedException
   *   If the entity doesn't have the data necessary for the classification.
   */
  protected function validateEntityData(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool {
    return TRUE;
  }

}
