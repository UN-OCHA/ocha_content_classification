<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines local tasks for entity types with simulation routes.
 */
class SimulateClassificationLocalTask extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

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
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];

    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    $entity_types = $this->entityTypeManager->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!($entity_type instanceof ContentEntityTypeInterface)) {
        continue;
      }

      if ($entity_type->hasLinkTemplate('canonical')) {
        $this->derivatives["entity.$entity_type_id.ocha_content_classification_simulate"] = [
          'route_name' => "entity.$entity_type_id.ocha_content_classification_simulate",
          'title' => $this->t('Simulate classification'),
          'base_route' => "entity.$entity_type_id.canonical",
          'weight' => 51,
        ] + $base_plugin_definition;
      }
    }

    return $this->derivatives;
  }

}
