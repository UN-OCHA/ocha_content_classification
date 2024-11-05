<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Entity;

use Drupal\Core\Config\Entity\ConfigEntityStorage;

/**
 * Storage handler for classification_workflow entities.
 */
class ClassificationWorkflowStorage extends ConfigEntityStorage implements ClassificationWorkflowStorageInterface {

  /**
   * {@inheritdoc}
   */
  protected function doCreate(array $values): ClassificationWorkflowInterface {
    $entity = parent::doCreate($values);
    assert($entity instanceof ClassificationWorkflowInterface);
    return $entity;
  }

}
