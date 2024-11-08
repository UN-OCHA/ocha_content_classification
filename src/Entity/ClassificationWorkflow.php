<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ocha_content_classification\Exception\AlreadyProcessedException;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Exception\UnsupportedEntityException;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginManagerInterface;

/**
 * Defines the Classification Workflow configuration entity.
 *
 * @ConfigEntityType(
 *   id = "ocha_classification_workflow",
 *   label = @Translation("Classification Workflow"),
 *   handlers = {
 *     "list_builder" = "Drupal\ocha_content_classification\ClassificationWorkflowListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ocha_content_classification\Form\ClassificationWorkflowAddForm",
 *       "edit" = "Drupal\ocha_content_classification\Form\ClassificationWorkflowEditForm",
 *       "fields" = "Drupal\ocha_content_classification\Form\ClassificationWorkflowFieldsForm",
 *       "classifier" = "Drupal\ocha_content_classification\Form\ClassificationWorkflowClassifierForm",
 *       "delete" = "Drupal\ocha_content_classification\Form\ClassificationWorkflowDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "ocha_classification_workflow",
 *   admin_permission = "administer ocha content classification workflows",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/ocha-content-classification/classification-workflows/{ocha_classification_workflow}",
 *     "add-form" = "/admin/config/ocha-content-classification/classification-workflows/add",
 *     "edit-form" = "/admin/config/ocha-content-classification/classification-workflows/{ocha_classification_workflow}/edit",
 *     "fields-form" = "/admin/config/ocha-content-classification/classification-workflows/{ocha_classification_workflow}/configure-fields",
 *     "classifier-form" = "/admin/config/ocha-content-classification/classification-workflows/{ocha_classification_workflow}/configure-classifier",
 *     "delete-form" = "/admin/config/ocha-content-classification/classification-workflows/{ocha_classification_workflow}/delete",
 *     "collection" = "/admin/config/ocha-content-classification/classification-workflows"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "limit",
 *     "target",
 *     "fields",
 *     "classifier"
 *   }
 * )
 */
class ClassificationWorkflow extends ConfigEntityBase implements ClassificationWorkflowInterface {

  /**
   * The Classification Workflow ID.
   *
   * @var string
   */
  protected ?string $id;

  /**
   * The Classification Workflow label.
   *
   * @var string
   */
  protected ?string $label;

  /**
   * Maximum number of attempts before failure.
   *
   * @var int
   */
  protected ?int $limit;

  /**
   * The workflow target settings.
   *
   * @var array
   */
  protected ?array $target;

  /**
   * The workflow fields settings.
   *
   * @var array
   */
  protected ?array $fields;

  /**
   * The workflow classifier settings.
   *
   * @var array
   */
  protected ?array $classifier;

  /**
   * The classifier plugin.
   *
   * @var \Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface
   */
  protected ClassifierPluginInterface $classifierPlugin;

  /**
   * The classifier plugin manager.
   *
   * @var \Drupal\ocha_content_classification\Plugin\ClassifierPluginManagerInterface
   */
  protected ClassifierPluginManagerInterface $classifierPluginManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public function enabled(): bool {
    return $this->status();
  }

  /**
   * {@inheritdoc}
   */
  public function setId(?string $id): self {
    $this->id = $id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setLabel(?string $label): self {
    $this->label = $label;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttemptsLimit(): int {
    return $this->limit ?? 1;
  }

  /**
   * {@inheritdoc}
   */
  public function setAttemptsLimit(?int $limit): self {
    $this->limit = isset($limit) ? max(1, $limit) : NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): ?string {
    return $this->target['entity_type_id'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntityTypeId(?string $entity_type_id): self {
    $this->target['entity_type_id'] = $entity_type_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetBundle(): ?string {
    return $this->target['bundle'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetBundle(?string $bundle): self {
    $this->target['bundle'] = $bundle;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifierPluginId(): ?string {
    return $this->classifier['id'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifierPluginId(?string $plugin_id): self {
    // Reset the classifier plugin since it may change.
    unset($this->classifierPlugin);
    $this->classifier['id'] = $plugin_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifierPluginSettings(): ?array {
    return $this->classifier['settings'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifierPluginSettings(?array $settings): self {
    // Reset the classifier plugin since it may change.
    unset($this->classifierPlugin);
    $this->classifier['settings'] = $settings;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifierPlugin(): ?ClassifierPluginInterface {
    if (isset($this->classifierPlugin)) {
      return $this->classifierPlugin;
    }

    $classifier_plugin_manager = $this->getClassifierPluginManager();

    $plugin_id = $this->getClassifierPluginId();
    if (!empty($plugin_id) && $classifier_plugin_manager->hasDefinition($plugin_id)) {
      $plugin_settings = $this->getClassifierPluginSettings() ?? [];
      $this->classifierPlugin = $classifier_plugin_manager->createInstance($plugin_id, $plugin_settings);
      return $this->classifierPlugin;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isAnalyzableFieldEnabled(string $field_name): bool {
    return !empty($this->fields['analyzable'][$field_name]['enabled']);
  }

  /**
   * {@inheritdoc}
   */
  public function setAnalyzableFieldEnabled(string $field_name, bool $enabled): self {
    $this->fields['analyzable'][$field_name]['enabled'] = $enabled;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isClassifiableFieldEnabled(string $field_name): bool {
    return !empty($this->fields['classifiable'][$field_name]['enabled']);
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifiableFieldEnabled(string $field_name, bool $enabled): self {
    $this->fields['classifiable'][$field_name]['enabled'] = $enabled;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifiableFieldMin(string $field_name): int {
    return (int) ($this->fields['classifiable'][$field_name]['min'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifiableFieldMin(string $field_name, int $min): self {
    $this->fields['classifiable'][$field_name]['min'] = max(0, $min);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifiableFieldMax(string $field_name): int {
    return (int) ($this->fields['classifiable'][$field_name]['max'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifiableFieldMax(string $field_name, int $max): self {
    $this->fields['classifiable'][$field_name]['max'] = max(-1, $max);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledAnalyzableFields(): array {
    $fields = [];
    foreach ($this->fields['analyzable'] ?? [] as $field_name => $field_info) {
      if (!empty($field_info['enabled'])) {
        $fields[$field_name] = $field_info;
      }
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledClassifiableFields(): array {
    $fields = [];
    foreach ($this->fields['classifiable'] ?? [] as $field_name => $field_info) {
      if (!empty($field_info['enabled'])) {
        $fields[$field_name] = $field_info;
      }
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function classifyEntity(ContentEntityInterface $entity): bool {
    if ($this->validateEntity($entity)) {
      return $this->getClassifierPlugin()?->classifyEntity($entity, $this) ?? FALSE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity, bool $check_status = TRUE): bool {
    if ($entity->getEntityTypeId() !== $this->getTargetEntityTypeId() || $entity->bundle() !== $this->getTargetBundle()) {
      throw new UnsupportedEntityException('Entity type or bundle not supported.');
    }

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);
    $existing_record = $this->getClassificationProgress($entity);
    $status = $existing_record['status'] ?? '';
    $attempts = $existing_record['attempts'] ?? 0;

    // Skip if the entity is already processed.
    if ($check_status && $status === 'processed') {
      throw new AlreadyProcessedException(strtr('@bundle_label @id already processed.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Skip if the classification is marked as failure.
    if ($check_status && $status === 'failed') {
      throw new ClassificationFailedException(strtr('Classification failed for @bundle_label @id.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Skip if we reached the maximum number of classification attempts.
    if ($check_status && $attempts >= $this->getAttemptsLimit()) {
      throw new ClassificationFailedException(strtr('Maximum classification attempts reached for @bundle_label @id.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Check if any of the classifiable field has already been specified in
    // which case we skip the automated classification.
    foreach (array_keys($this->getEnabledClassifiableFields()) as $field_name) {
      // We cannot classify an entity missing fields.
      if (!$entity->hasField($field_name)) {
        throw new UnsupportedEntityException(strtr('@field_name missing for @bundle_label @id.', [
          '@field_name' => $field_name,
          '@bundle_label' => $bundle_label,
          '@id' => $entity->id(),
        ]));
      }

      // The field is not empty, we consider the classification done.
      if (!$entity->get($field_name)->isEmpty()) {
        throw new AlreadyProcessedException(strtr('@field_label already specified for @bundle_label @id.', [
          '@field_label' => $entity->get($field_name)->getFieldDefinition()->getLabel(),
          '@bundle_label' => $bundle_label,
          '@id' => $entity->id(),
        ]));
      }
    }

    // Finally validate the classifier.
    // @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
    return $this->getClassifierPlugin()?->validateEntity($entity) ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function updateClassificationProgress(
    ContentEntityInterface $entity,
    string $message,
    string $status,
    bool $new = FALSE,
  ): string {
    // Extract necessary information from the entity.
    $entity_type_id = $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();
    $entity_id = $entity->id();

    // Retrieve the existing progress record if any.
    $existing_record = $this->getClassificationProgress($entity);

    if (!empty($existing_record)) {
      // Update existing record: increment attempts and update status/message.
      $this->getDatabase()->update('ocha_content_classification_progress')
        ->fields([
          'status' => $status,
          'attempts' => $new ? 1 : $existing_record['attempts'] + 1,
          'created' => $new ? time() : $existing_record['created'],
          'changed' => time(),
          'message' => $message,
          'classifier' => $this->getClassifierPluginId(),
        ])
        ->condition('entity_type_id', $entity_type_id)
        ->condition('entity_bundle', $entity_bundle)
        ->condition('entity_id', $entity_id)
        ->execute();

      return $existing_record['status'];
    }
    else {
      // Insert a new record.
      $this->getDatabase()->insert('ocha_content_classification_progress')
        ->fields([
          'entity_type_id' => $entity_type_id,
          'entity_bundle' => $entity_bundle,
          'entity_id' => $entity_id,
          'status' => $status,
          'attempts' => 0,
          'created' => time(),
          'changed' => time(),
          'message' => $message,
          'classifier' => $this->getClassifierPluginId(),
        ])
        ->execute();

      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getClassificationProgress(ContentEntityInterface $entity): ?array {
    return $this->getDatabase()->select('ocha_content_classification_progress', 'ocp')
      ->fields('ocp')
      ->condition('entity_type_id', $entity->getEntityTypeId())
      ->condition('entity_bundle', $entity->bundle())
      ->condition('entity_id', $entity->id())
      ->execute()
      ?->fetchAssoc() ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteClassificationProgress(ContentEntityInterface $entity): void {
    $this->getDatabase()->delete('ocha_content_classification_progress')
      ->condition('entity_type_id', $entity->getEntityTypeId())
      ->condition('entity_bundle', $entity->bundle())
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * Get the classifier plugin manager.
   *
   * @return \Drupal\ocha_content_classification\Plugin\ClassifierPluginManagerInterface
   *   The classifier plugin manager.
   */
  protected function getClassifierPluginManager(): ClassifierPluginManagerInterface {
    if (!isset($this->classifierPluginManager)) {
      $this->classifierPluginManager = \Drupal::service('plugin.manager.ocha_content_classification.classifier');
    }
    return $this->classifierPluginManager;
  }

  /**
   * Get the database connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection.
   */
  protected function getDatabase(): Connection {
    if (!isset($this->database)) {
      $this->database = \Drupal::database();
    }
    return $this->database;
  }

}
