<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of Classification Workflow entities.
 */
class ClassificationWorkflowListBuilder extends ConfigEntityListBuilder {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage for the entity type.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info service.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['entity_type'] = $this->t('Entity Type');
    $header['bundle'] = $this->t('Bundle');
    $header['status'] = $this->t('Status');
    $header['classifier'] = $this->t('Classifier');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $entity */
    $row['label'] = $entity->label();

    // Label of the entity type this workflow applies to.
    $entity_type_id = $entity->getTargetEntityTypeId();
    if (isset($entity_type_id)) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $row['entity_type'] = $entity_type?->getLabel() ?: $this->t('Missing');
    }
    else {
      $row['entity_type'] = $this->t('Missing');
    }

    // Label of the entity bundle this workflow applies to.
    $bundle = $entity->getTargetBundle();
    if (isset($bundle)) {
      $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      $row['bundle'] = $bundle_info[$bundle]['label'] ?? $this->t('Missing');
    }
    else {
      $row['bundle'] = $this->t('Missing');
    }

    // Status of the workflow.
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');

    // Selected classifier if any.
    $classifier = $entity->getClassifierPlugin();
    if (isset($classifier)) {
      $row['classifier'] = $classifier->getPluginLabel();
    }
    else {
      $row['classifier'] = $this->t('None');
    }

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->access('update') && $entity->hasLinkTemplate('edit-form')) {
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'weight' => 10,
        'url' => $this->ensureDestination($entity->toUrl('edit-form')),
      ];
      $operations['edit-fields'] = [
        'title' => $this->t('Manage fields'),
        'weight' => 11,
        'url' => $this->ensureDestination($entity->toUrl('fields-form')),
      ];
      $operations['edit-classifier'] = [
        'title' => $this->t('Manage classifier'),
        'weight' => 12,
        'url' => $this->ensureDestination($entity->toUrl('classifier-form')),
      ];
    }
    if ($entity->access('delete') && $entity->hasLinkTemplate('delete-form')) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 100,
        'url' => $this->ensureDestination($entity->toUrl('delete-form')),
      ];
    }

    uasort($operations, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    return $operations;
  }

}
