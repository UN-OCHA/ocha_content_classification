<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Provides dynamic routes for OCHA Content Classification.
 */
final class OchaContentClassificationRoutes implements ContainerInjectionInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Return a collection of routes for content classification.
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
    // Get the different entity types.
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    $entity_types = $this->entityTypeManager->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!($entity_type instanceof ContentEntityTypeInterface)) {
        continue;
      }

      // Only create a requeue route if the entity type has a canonical link
      // so we can use it as base path.
      if ($entity_type->hasLinkTemplate('canonical')) {
        $canonical_path = $entity_type->getLinkTemplate('canonical');
        $route = $this->buildClassificationRequeueRoute($entity_type_id, $canonical_path);
        $collection->add("entity.$entity_type_id.ocha_content_classification_requeue", $route);
      }
    }
  }

  /**
   * Build a route for the given entity type.
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
      $canonical_path . '/ocha-content-classification-requeue',
      [
        '_entity_form' => "$entity_type_id.ocha-content-classification-requeue",
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
