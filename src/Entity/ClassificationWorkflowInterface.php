<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ocha_content_classification\Enum\ClassificationMessage;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface;

/**
 * Provides an interface for defining Classification Workflow entities.
 */
interface ClassificationWorkflowInterface extends ConfigEntityInterface {

  /**
   * Check if the workflow is enabled.
   *
   * @return bool
   *   TRUE if enabled.
   */
  public function enabled(): bool;

  /**
   * Set the workflow ID.
   *
   * @param ?string $id
   *   Workflow ID.
   *
   * @return $this
   */
  public function setId(?string $id): self;

  /**
   * Set the workflow label.
   *
   * @param ?string $label
   *   Workflow label.
   *
   * @return $this
   */
  public function setLabel(?string $label): self;

  /**
   * Get the allowed maximum number of classification attempts.
   *
   * @return int
   *   Attempts limit.
   */
  public function getAttemptsLimit(): int;

  /**
   * Set the allowed maximum number of classification attempts.
   *
   * @param ?int $limit
   *   Attempts limit.
   *
   * @return $this
   */
  public function setAttemptsLimit(?int $limit): self;

  /**
   * Get the list of enabled validation checks.
   *
   * @return array
   *   Associative array of enabled validation checks with the check names as
   *   keys and whether they are enabled or not as values.
   */
  public function getValidationChecks(): array;

  /**
   * Set the list of enabled validation checks.
   *
   * @param ?array $checks
   *   Associative array of enabled validation checks with the check names as
   *   keys and whether they are enabled or not as values.
   *
   * @return $this
   */
  public function setValidationChecks(?array $checks): self;

  /**
   * Indicate if a validation check is enabled.
   *
   * @param string $name
   *   Validation check name.
   *
   * @return bool
   *   TRUE if the validation check is enabled.
   */
  public function getValidationCheck(string $name): bool;

  /**
   * Get the entity type ID this workflow applies to.
   *
   * @return ?string
   *   The entity type ID.
   */
  public function getTargetEntityTypeId(): ?string;

  /**
   * Set the entity type ID this workflow applies to.
   *
   * @param ?string $entity_type_id
   *   The entity type ID.
   *
   * @return $this
   */
  public function setTargetEntityTypeId(?string $entity_type_id): self;

  /**
   * Get the bundle this workflow applies to.
   *
   * @return ?string
   *   The bundle.
   */
  public function getTargetBundle(): ?string;

  /**
   * Set the bundle this workflow applies to.
   *
   * @param ?string $bundle
   *   The bundle.
   *
   * @return $this
   */
  public function setTargetBundle(?string $bundle): self;

  /**
   * Get the classifier plugin ID.
   *
   * @return ?string
   *   The classifier plugin ID.
   */
  public function getClassifierPluginId(): ?string;

  /**
   * Set the classifier plugin ID.
   *
   * @param ?string $plugin_id
   *   The classifier plugin ID.
   *
   * @return $this
   */
  public function setClassifierPluginId(?string $plugin_id): self;

  /**
   * Get the classifier settings.
   *
   * @return ?array
   *   The classifier settings.
   */
  public function getClassifierPluginSettings(): ?array;

  /**
   * Set the classifier settings.
   *
   * @param array $settings
   *   The classifier settings.
   *
   * @return $this
   */
  public function setClassifierPluginSettings(?array $settings): self;

  /**
   * Get the classifier plugin for this workflow.
   *
   * @return ?\Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface
   *   The classifier plugin, or NULL if not set.
   */
  public function getClassifierPlugin(): ?ClassifierPluginInterface;

  /**
   * Check if a field is enabled for analyzable content.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is enabled, FALSE otherwise.
   */
  public function isAnalyzableFieldEnabled(string $field_name): bool;

  /**
   * Set the enabled status for an analyzable field.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $enabled
   *   TRUE to enable, FALSE to disable.
   *
   * @return $this
   */
  public function setAnalyzableFieldEnabled(string $field_name, bool $enabled): self;

  /**
   * Check if a field is enabled for classification.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is enabled, FALSE otherwise.
   */
  public function isClassifiableFieldEnabled(string $field_name): bool;

  /**
   * Set the enabled status for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $enabled
   *   TRUE to enable, FALSE to disable.
   *
   * @return $this
   */
  public function setClassifiableFieldEnabled(string $field_name, bool $enabled): self;

  /**
   * Get the minimum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The minimum value.
   */
  public function getClassifiableFieldMin(string $field_name): int;

  /**
   * Set the minimum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   * @param int $min
   *   The minimum value.
   *
   * @return $this
   */
  public function setClassifiableFieldMin(string $field_name, int $min): self;

  /**
   * Get the maximum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The maximum value.
   */
  public function getClassifiableFieldMax(string $field_name): int;

  /**
   * Set the maximum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   * @param int $max
   *   The maximum value.
   *
   * @return $this
   */
  public function setClassifiableFieldMax(string $field_name, int $max): self;

  /**
   * Get whether the classifiable field should be hidden in the form.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field should be hidden.
   */
  public function getClassifiableFieldHide(string $field_name): bool;

  /**
   * Set whether the classifiable field should be hidden in the form.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $hide
   *   TRUE if the field should be hidden.
   *
   * @return $this
   */
  public function setClassifiableFieldHide(string $field_name, bool $hide): self;

  /**
   * Get whether the field should be updated even if it already had a value.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the classification should be forced.
   */
  public function getClassifiableFieldForce(string $field_name): bool;

  /**
   * Set whether the field should be updated even if it already had a value.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $force
   *   TRUE if the classification should be forced.
   *
   * @return $this
   */
  public function setClassifiableFieldForce(string $field_name, bool $force): self;

  /**
   * Check if a fillable field is enabled.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is enabled, FALSE otherwise.
   */
  public function isFillableFieldEnabled(string $field_name): bool;

  /**
   * Set the enabled status for a fillable field.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $enabled
   *   TRUE to enable, FALSE to disable.
   *
   * @return $this
   */
  public function setFillableFieldEnabled(string $field_name, bool $enabled): self;

  /**
   * Get the properties of fillable field that can be filled.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return array
   *   Field properties.
   */
  public function getFillableFieldProperties(string $field_name): array;

  /**
   * Set the properties of fillable field that can be filled.
   *
   * @param string $field_name
   *   The field name.
   * @param array $properties
   *   Field properties.
   *
   * @return $this
   */
  public function setFillableFieldProperties(string $field_name, array $properties): self;

  /**
   * Get whether the classifiable field should be hidden in the form.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field should be hidden.
   */
  public function getFillableFieldHide(string $field_name): bool;

  /**
   * Set whether the classifiable field should be hidden in the form.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $hide
   *   TRUE if the field should be hidden.
   *
   * @return $this
   */
  public function setFillableFieldHide(string $field_name, bool $hide): self;

  /**
   * Get whether the field should be updated even if it already had a value.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the classification should be forced.
   */
  public function getFillableFieldForce(string $field_name): bool;

  /**
   * Set whether the field should be updated even if it already had a value.
   *
   * @param string $field_name
   *   The field name.
   * @param bool $force
   *   TRUE if the classification should be forced.
   *
   * @return $this
   */
  public function setFillableFieldForce(string $field_name, bool $force): self;

  /**
   * Get the list of enabled analyzable fields.
   *
   * @return array<string, mixed>
   *   An associative array of the analyzable fields keyed by field names.
   */
  public function getEnabledAnalyzableFields(): array;

  /**
   * Get the list of enabled classifiable fields.
   *
   * @return array<string, mixed>
   *   An associative array of the classifiable fields keyed by field names.
   */
  public function getEnabledClassifiableFields(): array;

  /**
   * Get the list of enabled fillable fields.
   *
   * @return array<string, mixed>
   *   An associative array of the fillable fields keyed by field names.
   */
  public function getEnabledFillableFields(): array;

  /**
   * Get the list of enabled for the given field categories.
   *
   * @param array $types
   *   List of field types: 'analyzable', 'classifiable' or 'fillable'.
   *
   * @return array<string, mixed>
   *   An associative array of the fields keyed by field names. They have a
   *   type property ('analyzable', 'classifiable' or 'fillable').
   */
  public function getEnabledFields(array $types): array;

  /**
   * Classify an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to classify.
   *
   * @return ?array
   *   The list of the entity fields that were updated if the classification was
   *   successful, NULL otherwise.
   */
  public function classifyEntity(ContentEntityInterface $entity): ?array;

  /**
   * Check if an entity can be processed by the workflow.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   * @param bool $check_status
   *   Whether to check the classification record status or not. This is used
   *   notably to allow requeueing entities after reverting to a revision where
   *   the classifiable fields are empty for example.
   *
   * @return bool
   *   TRUE if the entity can be processed.
   *
   * @throws \Drupal\ocha_content_classification\Exception\WorkflowNotEnabledException
   *   If the workflow is not enabled.
   * @throws \Drupal\ocha_content_classification\Exception\UnsupportedEntityException
   *   If the entity cannot be processed by the workflow (ex: missing fields).
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationCompletedException
   *   If the classification was already completed.
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationFailedException
   *   If the classification is marked as failed.
   * @throws \Drupal\ocha_content_classification\Exception\AttemptsLimitReachedException
   *   If the classification attempts limit was reached.
   * @throws \Drupal\ocha_content_classification\Exception\FieldAlreadySpecifiedException
   *   If a classifiable field is already specified (not empty).
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If the configuration is invalid (ex: missing settings).
   */
  public function validateEntity(ContentEntityInterface $entity, bool $check_status = TRUE): bool;

  /**
   * Update the classification progress for a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity being classified.
   * @param \Drupal\ocha_content_classification\Enum\ClassificationMessage $message
   *   The log message for this attempt (ex: success, error, temporary failure)
   * @param \Drupal\ocha_content_classification\Enum\ClassificationStatus $status
   *   The classification status (queued, processed, failed).
   * @param bool $new
   *   Optional flag to create a new record or reset existing ones when TRUE
   *   (ex: requeueing).
   * @param ?array $updated_fields
   *   List of updated fields during the classification.
   */
  public function updateClassificationProgress(
    ContentEntityInterface $entity,
    ClassificationMessage $message,
    ClassificationStatus $status,
    bool $new = FALSE,
    ?array $updated_fields = NULL,
  ): void;

  /**
   * Get the existing classification progress for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity being classified.
   *
   * @return ?array
   *   A record from the ocha_content_classification_progress table as an
   *   associative array or NULL if no record was found.
   */
  public function getClassificationProgress(ContentEntityInterface $entity): ?array;

  /**
   * Delete an existing classification progress for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity being classified.
   */
  public function deleteClassificationProgress(ContentEntityInterface $entity): void;

  /**
   * Get the permissions for the workflow.
   *
   * @return array
   *   Associative array with `apply`, `bypass` and `requeue` permissions. Each
   *   permission has an `id`, `title` and `description`.
   */
  public function getWorkflowPermissions(): array;

}
