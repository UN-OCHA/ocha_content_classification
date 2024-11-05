<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Classification Workflow fields form.
 */
class ClassificationWorkflowFieldsForm extends EntityForm {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructs a ClassificationWorkflowEditForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();

    // Add content fields section.
    $form['analyzable'] = [
      '#type' => 'details',
      '#title' => $this->t('Analyzable Content'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('List of fields that can be used as content to analyze for the classification. Placeholders can be used in the prompt and will be replaced with the processed field value.'),
    ];

    $form['analyzable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Enabled'),
        $this->t('Field'),
      ],
    ];

    $content_fields = $this->getContentFields($entity_type_id, $bundle);
    foreach ($content_fields as $field_name => $field_label) {
      $form['analyzable']['fields'][$field_name] = [
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $workflow->isAnalyzableFieldEnabled($field_name),
        ],
        'label' => [
          '#plain_text' => $field_label,
        ],
      ];
    }

    // Add taxonomy term reference fields section.
    $form['classifiable'] = [
      '#type' => 'details',
      '#title' => $this->t('Classifiable Content'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('List of fields that can be automatically classified. Placeholders can be used in the prompt and will be replaced with the list of terms. The number of terms to retrieve is determined by the min and max values.'),
    ];

    $form['classifiable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Enabled'),
        $this->t('Field'),
        $this->t('Min'),
        $this->t('Max'),
      ],
    ];

    $term_fields = $this->getTaxonomyTermReferenceFields($entity_type_id, $bundle);
    foreach ($term_fields as $field_name => $field_info) {
      $form['classifiable']['fields'][$field_name] = [
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $workflow->isClassifiableFieldEnabled($field_name),
        ],
        'label' => [
          '#plain_text' => $field_info['label'],
        ],
        'min' => [
          '#type' => 'number',
          '#title' => $this->t('Min'),
          '#title_display' => 'invisible',
          '#default_value' => $workflow->getClassifiableFieldMin($field_name),
          '#min' => 0,
        ],
        'max' => [
          '#type' => 'number',
          '#title' => $this->t('Max'),
          '#title_display' => 'invisible',
          '#default_value' => $workflow->getClassifiableFieldMax($field_name),
          '#min' => 1,
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    // This form is only to edit fields.
    unset($actions['delete']);
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    // This form only updates existing workflows.
    $this->messenger()->addStatus($this->t('Classification workflow %label has been updated.', [
      '%label' => $workflow->label(),
    ]));

    $form_state->setRedirectUrl($workflow->toUrl('collection'));

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $entity */
    // Reset the fields settings.
    $entity->set('fields', []);

    // Save analyzable fields.
    $analyzable_fields = $form_state->getValue(['analyzable', 'fields']);
    foreach ($analyzable_fields as $field_name => $field_data) {
      $entity->setAnalyzableFieldEnabled($field_name, (bool) $field_data['enabled']);
    }

    // Save classifiable fields.
    $classifiable_fields = $form_state->getValue(['classifiable', 'fields']);
    foreach ($classifiable_fields as $field_name => $field_data) {
      $entity->setClassifiableFieldEnabled($field_name, (bool) $field_data['enabled']);
      $entity->setClassifiableFieldMin($field_name, (int) $field_data['min']);
      $entity->setClassifiableFieldMax($field_name, (int) $field_data['max']);
    }
  }

  /**
   * Get content fields for the supported entity type and bundle.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return array<string, string>
   *   An array of field names and labels.
   */
  protected function getContentFields(string $entity_type_id, string $bundle): array {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    $allowed_types = ['text', 'text_long', 'text_with_summary'];
    $allowed_fields = [$entity_type->getKey('label')];
    // @todo retrieve that from some config.
    $disallowed_fields = [];

    $content_fields = [];
    foreach ($fields as $field_name => $field) {
      if (in_array($field_name, $disallowed_fields)) {
        continue;
      }
      if (in_array($field->getType(), $allowed_types) || in_array($field_name, $allowed_fields)) {
        $content_fields[$field_name] = $field->getLabel();
      }
    }

    return array_filter($content_fields);
  }

  /**
   * Get taxonomy term reference fields.
   *
   * Note: this only works well for fields that reference a single vocabulary.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return array<string, array<string, string>>
   *   An associative array of field information with field names as keys and
   *   with associative arrays with label and vocabulary properties as values.
   */
  protected function getTaxonomyTermReferenceFields(string $entity_type_id, string $bundle): array {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    $term_fields = [];
    foreach ($fields as $field_name => $field) {
      if ($field->getType() === 'entity_reference' && $field->getSetting('target_type') === 'taxonomy_term') {
        $bundles = $field->getSetting('handler_settings')['target_bundles'] ?? [];
        $vocabulary = reset($bundles);
        if (!empty($vocabulary)) {
          $term_fields[$field_name] = [
            'label' => $field->getLabel(),
            'vocabulary' => $vocabulary,
          ];
        }
      }
    }
    return $term_fields;
  }

}
