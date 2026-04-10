<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Symfony\Component\Routing\Route;

/**
 * Access check for the classification simulation (simulate) routes.
 */
class ClassificationSimulateAccessCheck implements AccessInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface $contentEntityClassifier
   *   The content entity classifier service.
   */
  public function __construct(
    protected ContentEntityClassifierInterface $contentEntityClassifier,
  ) {}

  /**
   * Checks access.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    $entity_type_id = $route_match->getRouteObject()?->getDefault('entity_type');
    if (empty($entity_type_id)) {
      return AccessResult::forbidden();
    }

    $entity = $route_match->getParameter($entity_type_id);
    if (empty($entity) || !($entity instanceof ContentEntityInterface)) {
      return AccessResult::forbidden();
    }

    $workflow = $this->contentEntityClassifier->getWorkflowForEntity($entity);
    if (empty($workflow) || $workflow->getClassifierPlugin() === NULL) {
      return AccessResult::forbidden();
    }

    $workflow_permissions = $workflow->getWorkflowPermissions();
    if (!$account->hasPermission($workflow_permissions['simulate']['id'])) {
      return AccessResult::forbidden();
    }

    // Require at least one non-empty analyzable field so the classifier has
    // input (mirrors classifier validation).
    $has_analyzable_data = FALSE;
    foreach ($workflow->getEnabledFields(['analyzable']) as $field_name => $_) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $has_analyzable_data = TRUE;
        break;
      }
    }
    if (!$has_analyzable_data) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

}
