<?php

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;

/**
 * Interface for the ocha_content_classification plugins.
 */
interface ClassifierPluginInterface {

  /**
   * Classify an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return bool
   *   TRUE if the classification was successful.
   */
  public function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool;

  /**
   * Check if an entity can be processed by the classifier.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   *
   * @return bool
   *   TRUE if the entity can be processed.
   *
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If mandatory settings are missing or some other configuration is invalid.
   */
  public function validateEntity(ContentEntityInterface $entity): bool;

}
