<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for adding a classification workflow.
 */
class ClassificationWorkflowAddForm extends EntityForm {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {
    $this->setEntityTypeManager($entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow Label'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['target'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Target'),
      '#tree' => TRUE,
    ];

    $form['target']['entity_type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Entity Type'),
      '#options' => $this->getContentEntityTypeOptions(),
      '#default_value' => $workflow->getTargetEntityTypeId(),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateBundleOptions',
        'wrapper' => 'target-bundle-wrapper',
      ],
    ];

    $form['target']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Bundle'),
      '#options' => $this->getBundleOptions($workflow->getTargetEntityTypeId()),
      '#default_value' => $workflow->getTargetBundle(),
      '#required' => TRUE,
      '#prefix' => '<div id="target-bundle-wrapper">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * Ajax callback to update bundle options.
   */
  public function updateBundleOptions(array &$form, FormStateInterface $form_state): array {
    return $form['target']['bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $target_entity_type_id = $form_state->getValue(['target']['entity_type_id']);
    $target_bundle = $form_state->getValue(['target']['bundle']);

    // Check if a workflow already exists for this entity type and bundle.
    $existing = $this->entityTypeManager->getStorage('classification_workflow')
      ->loadByProperties([
        'target.entity_type_id' => $target_entity_type_id,
        'target.bundle' => $target_bundle,
      ]);

    if (!empty($existing)) {
      $form_state->setError($form['target'], $this->t('A classification workflow already exists for this entity type and bundle.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    // This form only creates new workflows.
    $this->messenger()->addStatus($this->t('Classification workflow %label has been created.', [
      '%label' => $workflow->label(),
    ]));

    $form_state->setRedirectUrl($workflow->toUrl('collection'));

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $target_entity_type_id = $form_state->getValue(['target']['entity_type_id']);
    $target_bundle = $form_state->getValue(['target']['bundle']);

    $id = $this->generateWorkflowId($target_entity_type_id, $target_bundle);
    $label = $form_state->getValue(['label']);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $entity */
    $entity->set('id', $id);
    $entity->set('label', $label);
    $entity->setTargetEntityTypeId($target_entity_type_id);
    $entity->setTargetBundle($target_bundle);
  }

  /**
   * Get content entity type options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   An array of content entity type options.
   */
  protected function getContentEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->entityClassImplements(ContentEntityInterface::class)) {
        $options[$entity_type_id] = $entity_type->getLabel();
      }
    }
    return $options;
  }

  /**
   * Get bundle options for a given entity type.
   *
   * @param string|null $entity_type_id
   *   The entity type ID.
   *
   * @return array<string, string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   An array of bundle options.
   */
  protected function getBundleOptions(?string $entity_type_id): array {
    $options = [];
    if ($entity_type_id) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle_id => $bundle_info) {
        $options[$bundle_id] = $bundle_info['label'];
      }
    }
    return $options;
  }

  /**
   * Generate a unique workflow ID based on entity type and bundle.
   *
   * @param string $target_entity_type_id
   *   The target entity type ID.
   * @param string $target_bundle
   *   The target bundle.
   *
   * @return string
   *   A unique workflow ID.
   */
  protected function generateWorkflowId(string $target_entity_type_id, string $target_bundle): string {
    return 'ocha_content_classification_' . $target_entity_type_id . '_' . $target_bundle . '_workflow';
  }

}
