<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
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
    // the entity has been updated.
    $hook_entity_updated_fields = $this->moduleHandler->invokeAll(
      'ocha_content_classification_post_classify_entity',
      [$entity, $workflow, $this, $entity_updated_fields, $data]
    ) ?? [];

    $entity_updated_fields = array_unique(array_merge(
      $entity_updated_fields,
      $hook_entity_updated_fields,
    ));

    return $entity_updated_fields;
  }

}
