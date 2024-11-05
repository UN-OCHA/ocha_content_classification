<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;

/**
 * Interface for a service to handle content entity classification.
 */
interface ContentEntityClassifierInterface {

  /**
   * Check if an entity is classifiable.
   *
   * This checks is there a valid classification workflow for the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to classify.
   *
   * @return bool
   *   TRUE if the entity is classifiable.
   */
  public function isEntityClassifiable(EntityInterface $entity): bool;

  /**
   * Get the enabled classification workflow that can process the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to classify.
   *
   * @return ?\Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface
   *   The classification workflow if it exists and is enabled.
   */
  public function getWorkflowForEntity(EntityInterface $entity): ?ClassificationWorkflowInterface;

  /**
   * Requeue an entity for classification, resetting status and attempts.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to requeue.
   * @param bool $requeue
   *   TRUE to requeue the item.
   *
   * @return bool
   *   TRUE if the entity was queued.
   */
  public function queueEntity(EntityInterface $entity, bool $requeue = FALSE): bool;

  /**
   * Act on an entity before it is saved.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to be saved.
   */
  public function entityBeforeSave(EntityInterface $entity): void;

  /**
   * Act on an entity after it is saved.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity that was saved.
   */
  public function entityAfterSave(EntityInterface $entity): void;

  /**
   * Act on an entity being deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity that is being deleted.
   */
  public function entityDelete(EntityInterface $entity): void;

  /**
   * Alter form if it's for an entity that can be automatically classified.
   *
   * @param array $form
   *   The full form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void;

}
