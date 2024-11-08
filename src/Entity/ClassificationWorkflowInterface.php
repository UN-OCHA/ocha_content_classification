<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
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
   * Checks if a field is enabled for analyzable content.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is enabled, FALSE otherwise.
   */
  public function isAnalyzableFieldEnabled(string $field_name): bool;

  /**
   * Sets the enabled status for an analyzable field.
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
   * Checks if a field is enabled for classification.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is enabled, FALSE otherwise.
   */
  public function isClassifiableFieldEnabled(string $field_name): bool;

  /**
   * Sets the enabled status for a classifiable field.
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
   * Gets the minimum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The minimum value.
   */
  public function getClassifiableFieldMin(string $field_name): int;

  /**
   * Sets the minimum value for a classifiable field.
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
   * Gets the maximum value for a classifiable field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The maximum value.
   */
  public function getClassifiableFieldMax(string $field_name): int;

  /**
   * Sets the maximum value for a classifiable field.
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
   * Classify an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to classify.
   *
   * @return bool
   *   TRUE if the classification was successful.
   */
  public function classifyEntity(ContentEntityInterface $entity): bool;

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
   * @throws \Drupal\ocha_content_classification\Exception\AlreadyProcessedException
   *   If the entity has already been processed.
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationFailedException
   *   If the classification is marked as failed for the entity.
   * @throws \Drupal\ocha_content_classification\Exception\UnsupportedEntityException
   *   If the entity cannot be processed by the workflow.
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If the configuration is invalid (ex: missing settings).
   */
  public function validateEntity(ContentEntityInterface $entity, bool $check_status = TRUE): bool;

  /**
   * Update the classification progress for a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity being classified.
   * @param string $message
   *   The log message for this attempt (ex: success, error, temporary failure)
   * @param string $status
   *   The classification status (queued, processed, skipped).
   * @param bool $new
   *   Optional flag to create a new record or reset existing ones when TRUE
   *   (ex: requeueing).
   *
   * @return string
   *   The previous record status.
   */
  public function updateClassificationProgress(
    ContentEntityInterface $entity,
    string $message,
    string $status,
    bool $new = FALSE,
  ): string;

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

}
