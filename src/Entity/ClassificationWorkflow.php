<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ocha_content_classification\Enum\ClassificationMessage;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Exception\AttemptsLimitReachedException;
use Drupal\ocha_content_classification\Exception\ClassificationCompletedException;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Exception\ClassificationSkippedException;
use Drupal\ocha_content_classification\Exception\FieldAlreadySpecifiedException;
use Drupal\ocha_content_classification\Exception\UnsupportedEntityException;
use Drupal\ocha_content_classification\Exception\WorkflowNotEnabledException;
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

  use StringTranslationTrait;

  /**
   * The Classification Workflow ID.
   *
   * @var ?string
   */
  protected ?string $id;

  /**
   * The Classification Workflow label.
   *
   * @var ?string
   */
  protected ?string $label;

  /**
   * Maximum number of attempts before failure.
   *
   * @var ?int
   */
  protected ?int $limit;

  /**
   * List of validation checks to perform.
   *
   * @var ?array
   */
  protected ?array $validation;

  /**
   * The workflow target settings.
   *
   * @var ?array
   */
  protected ?array $target;

  /**
   * The workflow fields settings.
   *
   * @var ?array
   */
  protected ?array $fields;

  /**
   * The workflow classifier settings.
   *
   * @var ?array
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
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

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
  public function getValidationChecks(): array {
    return $this->validation ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setValidationChecks(?array $checks): self {
    $this->validation = array_map(fn($check) => (bool) $check, $checks ?? []);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getValidationCheck(string $name): bool {
    return !empty($this->validation[$name]);
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
  public function getClassifiableFieldHide(string $field_name): bool {
    return $this->fields['classifiable'][$field_name]['hide'] ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifiableFieldHide(string $field_name, bool $hide): self {
    $this->fields['classifiable'][$field_name]['hide'] = $hide;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassifiableFieldForce(string $field_name): bool {
    return $this->fields['classifiable'][$field_name]['force'] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setClassifiableFieldForce(string $field_name, bool $force): self {
    $this->fields['classifiable'][$field_name]['force'] = $force;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isFillableFieldEnabled(string $field_name): bool {
    return !empty($this->fields['fillable'][$field_name]['enabled']);
  }

  /**
   * {@inheritdoc}
   */
  public function setFillableFieldEnabled(string $field_name, bool $enabled): self {
    $this->fields['fillable'][$field_name]['enabled'] = $enabled;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFillableFieldProperties(string $field_name): array {
    return $this->fields['fillable'][$field_name]['properties'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setFillableFieldProperties(string $field_name, array $properties): self {
    $this->fields['fillable'][$field_name]['properties'] = array_values(array_filter($properties));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFillableFieldHide(string $field_name): bool {
    return $this->fields['fillable'][$field_name]['hide'] ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setFillableFieldHide(string $field_name, bool $hide): self {
    $this->fields['fillable'][$field_name]['hide'] = $hide;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFillableFieldForce(string $field_name): bool {
    return $this->fields['fillable'][$field_name]['force'] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setFillableFieldForce(string $field_name, bool $force): self {
    $this->fields['fillable'][$field_name]['force'] = $force;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledAnalyzableFields(): array {
    return $this->getEnabledFields(['analyzable']);
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledClassifiableFields(): array {
    return $this->getEnabledFields(['classifiable']);
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledFillableFields(): array {
    return $this->getEnabledFields(['fillable']);
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledFields(array $types): array {
    $fields = [];
    foreach ($types as $type) {
      foreach ($this->fields[$type] ?? [] as $field_name => $field_info) {
        if (!empty($field_info['enabled'])) {
          $fields[$field_name] = $field_info + [
            'type' => $type,
          ];
        }
      }
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function classifyEntity(ContentEntityInterface $entity): ?array {
    if ($this->validateEntity($entity)) {
      return $this->getClassifierPlugin()?->classifyEntity($entity, $this);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity, bool $check_status = TRUE): bool {
    if (!$this->enabled()) {
      throw new WorkflowNotEnabledException(strtr('Workflow @id not enabled.', [
        '@id' => $this->id(),
      ]));
    }

    if ($entity->getEntityTypeId() !== $this->getTargetEntityTypeId() || $entity->bundle() !== $this->getTargetBundle()) {
      throw new UnsupportedEntityException('Entity type or bundle not supported.');
    }

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);
    $existing_record = $this->getClassificationProgress($entity);
    $status = $existing_record['status'] ?? '';
    $attempts = $existing_record['attempts'] ?? 0;

    // Allow modules to indicate the classification of the given entity should
    // be skipped.
    $skip_classification = FALSE;
    $skip_classification_context = ['entity' => $entity];
    $this->getModuleHandler()->alter(
      'ocha_content_classification_skip_classification',
      $skip_classification,
      $workflow,
      $skip_classification_context,
    );
    if ($skip_classification === TRUE) {
      throw new ClassificationSkippedException(strtr('Classification skipped for @bundle_label @id.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Skip if the classification is marked as completed.
    if ($check_status && $status === ClassificationStatus::Completed) {
      throw new ClassificationCompletedException(strtr('Classification already completed for @bundle_label @id.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Skip if the classification is marked as failure.
    if ($check_status && $status === ClassificationStatus::Failed) {
      throw new ClassificationFailedException(strtr('Classification previously failed for @bundle_label @id.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Skip if we reached the maximum number of classification attempts.
    if ($check_status && $attempts >= $this->getAttemptsLimit()) {
      throw new AttemptsLimitReachedException(strtr('Limit of @limit classification attempts reached for @bundle_label @id.', [
        '@limit' => $this->getAttemptsLimit(),
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    // Check if any of the classifiable or fillable field has already been
    // specified (i.e. has a value) in which case we skip the automated
    // classification.
    if ($this->getValidationCheck('empty')) {
      $fields_to_check = $this->getEnabledFields(['classifiable', 'fillable']);
      $fields_to_check = array_map(fn($field) => TRUE, $fields_to_check);

      // Allow modules to determine which fields should be check to determine
      // if the automated classification is allowed.
      $fields_to_check_context = ['entity' => $entity];
      $this->getModuleHandler()->alter(
        'ocha_content_classification_specified_field_check',
        $fields_to_check,
        $this,
        $fields_to_check_context,
      );

      foreach ($fields_to_check as $field_name => $check) {
        // We cannot classify an entity missing field.
        if (!$entity->hasField($field_name)) {
          throw new UnsupportedEntityException(strtr('@field_name missing for @bundle_label @id.', [
            '@field_name' => $field_name,
            '@bundle_label' => $bundle_label,
            '@id' => $entity->id(),
          ]));
        }

        // The field is not empty, we consider the classification done.
        // @todo Check if we want to skip the full classification or simply
        // skip the update of the field.
        if ($check && !$entity->get($field_name)->isEmpty()) {
          throw new FieldAlreadySpecifiedException(strtr('@field_label already specified for @bundle_label @id.', [
            '@field_label' => $entity->get($field_name)->getFieldDefinition()->getLabel(),
            '@bundle_label' => $bundle_label,
            '@id' => $entity->id(),
          ]));
        }
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
    ClassificationMessage $message,
    ClassificationStatus $status,
    bool $new = FALSE,
    ?array $updated_fields = NULL,
  ): void {
    // Extract necessary information from the entity.
    $entity_type_id = $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();
    $entity_id = $entity->id();
    $entity_revision_id = $entity->getRevisionId();

    // When creating or resetting a record, if the status is not queued, then
    // we consider there was one attempt already.
    $new_record_attempts = ($new && $status === ClassificationStatus::Queued) ? 0 : 1;

    // Get the current timestamp.
    $time = time();

    // Get the ID of the classifier plugin.
    $classifier = $this->getClassifierPluginId();

    // Retrieve the existing progress record if any.
    $existing_record = $this->getClassificationProgress($entity);

    if (!empty($existing_record)) {
      // Update existing record. If new was specified, reset the user ID,
      // creation time and attempts.
      $this->getDatabase()->update('ocha_content_classification_progress')
        ->fields([
          'entity_revision_id' => $entity_revision_id,
          'user_id' => $new ? $this->getCurrentUser()->id() : $existing_record['user_id'],
          'status' => $status->value,
          'attempts' => $new ? $new_record_attempts : $existing_record['attempts'] + 1,
          'created' => $new ? $time : $existing_record['created'],
          'changed' => $time,
          'message' => $message->value,
          'classifier' => $classifier,
          'updated_fields' => json_encode($updated_fields),
        ])
        ->condition('entity_type_id', $entity_type_id)
        ->condition('entity_bundle', $entity_bundle)
        ->condition('entity_id', $entity_id)
        ->execute();
    }
    else {
      // Insert a new record.
      $this->getDatabase()->insert('ocha_content_classification_progress')
        ->fields([
          'entity_type_id' => $entity_type_id,
          'entity_bundle' => $entity_bundle,
          'entity_id' => $entity_id,
          'entity_revision_id' => $entity_revision_id,
          'user_id' => $this->getCurrentUser()->id(),
          'status' => $status->value,
          'attempts' => $new_record_attempts,
          'created' => $time,
          'changed' => $time,
          'message' => $message->value,
          'classifier' => $classifier,
          'updated_fields' => json_encode($updated_fields),
        ])
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getClassificationProgress(ContentEntityInterface $entity): ?array {
    $record = $this->getDatabase()->select('ocha_content_classification_progress', 'ocp')
      ->fields('ocp')
      ->condition('entity_type_id', $entity->getEntityTypeId())
      ->condition('entity_bundle', $entity->bundle())
      ->condition('entity_id', $entity->id())
      ->execute()
      ?->fetchAssoc() ?: NULL;

    // Ensure the record properties have the proper types.
    if (isset($record['entity_id'])) {
      $record['entity_id'] = (int) $record['entity_id'];
    }
    if (isset($record['revision_id'])) {
      $record['revision_id'] = (int) $record['revision_id'];
    }
    if (isset($record['user_id'])) {
      $record['user_id'] = (int) $record['user_id'];
    }
    if (isset($record['status'])) {
      $record['status'] = ClassificationStatus::tryFrom($record['status']) ?? '';
    }
    if (isset($record['attempts'])) {
      $record['attempts'] = (int) $record['attempts'];
    }
    if (isset($record['created'])) {
      $record['created'] = (int) $record['created'];
    }
    if (isset($record['changed'])) {
      $record['changed'] = (int) $record['changed'];
    }
    if (isset($record['message'])) {
      $record['message'] = ClassificationMessage::tryFrom($record['message']) ?? '';
    }
    if (isset($record['updated_fields'])) {
      $record['updated_fields'] = json_decode($record['updated_fields']);
    }

    return $record;
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
   * {@inheritdoc}
   */
  public function getWorkflowPermissions(): array {
    $arguments = ['@workflow' => $this->label()];
    $entity_type_id = $this->getTargetEntityTypeId();
    $bundle = $this->getTargetBundle();

    $permissions = [
      'apply' => [
        'id' => "apply ocha content classification to $entity_type_id $bundle",
        'title' => $this->t('Apply content classification to @workflow', $arguments),
        'description' => $this->t('Allow users to have their @workflow automatically classified.', $arguments),
      ],
      'bypass' => [
        'id' => "bypass ocha content classification for $entity_type_id $bundle",
        'title' => $this->t('Bypass content classification for @workflow', $arguments),
        'description' => $this->t('Allow users to skip the automated classification when submitting @workflow.', $arguments),
      ],
      'requeue' => [
        'id' => "requeue $entity_type_id $bundle for ocha content classification",
        'title' => $this->t('Requeue @workflow for content classification', $arguments),
        'description' => $this->t('Allow users to resubmit @workflow for automated classification.', $arguments),
      ],
    ];

    return $permissions;
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

  /**
   * Get the module handler.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  protected function getModuleHandler(): ModuleHandlerInterface {
    if (!isset($this->moduleHandler)) {
      $this->moduleHandler = \Drupal::moduleHandler();
    }
    return $this->moduleHandler;
  }

  /**
   * Get the current user.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   *   The current user.
   */
  protected function getCurrentUser(): AccountProxyInterface {
    if (!isset($this->currentUser)) {
      $this->currentUser = \Drupal::currentUser();
    }
    return $this->currentUser;
  }

}
