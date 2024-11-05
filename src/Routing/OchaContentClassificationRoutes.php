<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides dynamic routes for OCHA Content Classification.
 */
final class OchaContentClassificationRoutes {

  /**
   * Constructs a new OchaContentClassificationRoutes object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a collection of routes for content classification.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A collection of routes for content classification.
   */
  public function routes(): RouteCollection {
    $collection = new RouteCollection();

    $this->generateClassificationRequeueRoutes($collection);

    return $collection;
  }

  /**
   * Generate requeue classification reoutes for entity types.
   *
   * @param \Symfony\Component\Routing\RouteCollection $collection
   *   Route collection.
   */
  private function generateClassificationRequeueRoutes(RouteCollection $collection): void {
    // Get the list of enabled classification workflows.
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface[] $workflows */
    $workflows = $this->entityTypeManager
      ->getStorage('ocha_content_classification_workflow')
      ->loadByProperties([
        'status' => 1,
      ]);

    if (!empty($workflows)) {
      // Extract their target entity types so we can create requeue routes
      // for them. The access check will take care of checking if there is an
      // enabled workflow for the entity bundle.
      $target_entity_type_ids = [];
      foreach ($workflows as $workflow) {
        $target_entity_type_ids[$workflow->getTargetEntityTypeId()] = TRUE;
      }

      // Get the different entity types.
      /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
      $entity_types = $this->entityTypeManager->getDefinitions();
      foreach ($entity_types as $entity_type_id => $entity_type) {
        if (!isset($target_entity_type_ids[$entity_type_id])) {
          continue;
        }

        // Only create a requeue route if the entity type has a canonical link
        // so we can use it as base path.
        if ($entity_type->hasLinkTemplate('canonical')) {
          $canonical_path = $entity_type->getLinkTemplate('canonical');
          $route = $this->buildClassificationRequeueRoute($entity_type_id, $canonical_path);
          $collection->add("ocha_content_classification.requeue.$entity_type_id", $route);
        }
      }
    }
  }

  /**
   * Builds a route for the given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $canonical_path
   *   The canonical path for the entity type.
   *
   * @return \Symfony\Component\Routing\Route
   *   The built route.
   */
  private function buildClassificationRequeueRoute(string $entity_type_id, string $canonical_path): Route {
    return new Route(
      $canonical_path . '/requeue-classification',
      [
        '_form' => '\Drupal\ocha_content_classification\Form\ClassificationRequeueForm',
        '_title' => 'Requeue for classification',
        'entity_type' => $entity_type_id,
      ],
      [
        '_permission' => 'requeue entity for ocha content classification',
        '_classification_requeue_access_check' => 'TRUE',
        '_entity_access' => $entity_type_id . '.update',
      ],
      [
        'parameters' => [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ],
      ]
    );
  }

}
