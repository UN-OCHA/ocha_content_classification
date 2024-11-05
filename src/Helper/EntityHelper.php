<?php

namespace Drupal\ocha_content_classification\Helpers;

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
  public static function getBundleLabelFromEntity(EntityInterface $entity) {
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
  public static function getBundleLabel($entity_type_id, $bundle) {
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
    return $bundle_info[$bundle]['label'] ?? $bundle;
  }

}
