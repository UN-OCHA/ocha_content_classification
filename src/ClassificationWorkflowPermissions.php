<?php

namespace Drupal\ocha_content_classification;

/**
 * Provides dynamic permissions for OCHA Content Classification workflows.
 */
class ClassificationWorkflowPermissions {

  /**
   * Returns an array of OCHA Content Classification permissions.
   *
   * @return array
   *   List of permissions.
   */
  public function permissions(): array {
    $permissions = [];

    // Get all ocha_classification_workflow entities.
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface[] $workflows */
    $workflows = \Drupal::entityTypeManager()
      ->getStorage('ocha_classification_workflow')
      ->loadMultiple();

    foreach ($workflows as $workflow) {
      foreach ($workflow->getWorkflowPermissions() as $permission) {
        $permissions[$permission['id']] = [
          'title' => $permission['title'],
          'description' => $permission['description'],
        ];
      }
    }

    return $permissions;
  }

}
