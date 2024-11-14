<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Helper;

use Drupal\Core\Entity\EntityInterface;

/**
 * Helper to information about entities.
 */
class EntityHelper {

  /**
   * Get a bundle's label from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Bundle label.
   */
  public static function getBundleLabelFromEntity(EntityInterface $entity): string {
    return static::getBundleLabel($entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * Get a bundle's label.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return string
   *   Bundle label.
   */
  public static function getBundleLabel(string $entity_type_id, string $bundle): string {
    /** @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info */
    $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');
    $bundle_info = $entity_type_bundle_info->getBundleInfo($entity_type_id);
    return $bundle_info[$bundle]['label'] ?? $bundle;
  }

}
