<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
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
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    ModuleHandlerInterface $module_handler,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    // Add analyzable fields section.
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
        ['data' => $this->t('Enabled'), 'style' => 'width: 5%'],
        ['data' => $this->t('Field'), 'style' => 'width: 95%'],
      ],
    ];

    $analyzable_fields = $this->getAnalyzableFields($workflow);
    foreach ($analyzable_fields as $field_name => $field_label) {
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
      '#description' => $this->t('List of fields that can be automatically classified. Placeholders can be used in the prompt and will be replaced with the list of terms. The number of terms to retrieve is determined by the min and max values. A max value of -1 means no upper limit. Check "hide" to hide the field from the entity form. Check "force" to update the field even if it already had a value.'),
    ];

    $form['classifiable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Enabled'), 'style' => 'width: 5%'],
        ['data' => $this->t('Field'), 'style' => 'width: 55%'],
        ['data' => $this->t('Min'), 'style' => 'width: 15%'],
        ['data' => $this->t('Max'), 'style' => 'width: 15%'],
        ['data' => $this->t('Hide'), 'style' => 'width: 5%'],
        ['data' => $this->t('Force'), 'style' => 'width: 5%'],
      ],
    ];

    $term_fields = $this->getTaxonomyTermReferenceFields($workflow);
    foreach ($term_fields as $field_name => $field_info) {
      $enabled = $workflow->isClassifiableFieldEnabled($field_name);

      $form['classifiable']['fields'][$field_name] = [
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $enabled,
        ],
        'label' => [
          '#plain_text' => $field_info['label'],
        ],
        'min' => [
          '#type' => 'number',
          '#title' => $this->t('Min'),
          '#title_display' => 'invisible',
          '#default_value' => $workflow->getClassifiableFieldMin($field_name) ?: $field_info['min'],
          '#min' => 0,
        ],
        'max' => [
          '#type' => 'number',
          '#title' => $this->t('Max'),
          '#title_display' => 'invisible',
          '#default_value' => $workflow->getClassifiableFieldMax($field_name) ?: $field_info['max'],
          '#min' => -1,
        ],
        'hide' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide'),
          '#title_display' => 'invisible',
          '#default_value' => $enabled && $workflow->getClassifiableFieldHide($field_name),
        ],
        'force' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Force'),
          '#title_display' => 'invisible',
          '#default_value' => $enabled && $workflow->getClassifiableFieldForce($field_name),
        ],
      ];
    }

    // Add fillable content fields section.
    $form['fillable'] = [
      '#type' => 'details',
      '#title' => $this->t('Fillable Content'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('List of content fields that can be filled by the classifier.'),
    ];

    $form['fillable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Enabled'), 'style' => 'width: 5%'],
        ['data' => $this->t('Field'), 'style' => 'width: 40%'],
        ['data' => $this->t('Properties'), 'style' => 'width: 45%'],
        ['data' => $this->t('Hide'), 'style' => 'width: 5%'],
        ['data' => $this->t('Force'), 'style' => 'width: 5%'],
      ],
    ];

    $fillable_fields = $this->getFillableFields($workflow);
    foreach ($fillable_fields as $field_name => $field) {
      if (empty($field['properties'])) {
        continue;
      }

      $enabled = $workflow->isFillableFieldEnabled($field_name);

      if (count($field['properties']) === 1) {
        $properties = [
          '#type' => 'value',
          '#markup' => $this->t('N/A'),
          '#value' => key($field['properties']),
        ];
      }
      else {
        $properties = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Properties'),
          '#title_display' => 'invisible',
          '#options' => $field['properties'],
          '#default_value' => $enabled ? $workflow->getFillableFieldProperties($field_name) : [],
        ];
      }

      $form['fillable']['fields'][$field_name] = [
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $enabled,
        ],
        'label' => [
          '#plain_text' => $field['label'],
        ],
        'properties' => $properties,
        'hide' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide'),
          '#title_display' => 'invisible',
          '#default_value' => $enabled && $workflow->getFillableFieldHide($field_name),
        ],
        'force' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Force'),
          '#title_display' => 'invisible',
          '#default_value' => $enabled && $workflow->getFillableFieldForce($field_name),
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
      if (empty($field_data['enabled'])) {
        continue;
      }
      $entity->setAnalyzableFieldEnabled($field_name, (bool) $field_data['enabled']);
    }

    // Save classifiable fields.
    $classifiable_fields = $form_state->getValue(['classifiable', 'fields']);
    foreach ($classifiable_fields as $field_name => $field_data) {
      if (empty($field_data['enabled'])) {
        continue;
      }
      $entity->setClassifiableFieldEnabled($field_name, (bool) $field_data['enabled']);
      $entity->setClassifiableFieldMin($field_name, (int) $field_data['min']);
      $entity->setClassifiableFieldMax($field_name, (int) $field_data['max']);
      $entity->setClassifiableFieldHide($field_name, (bool) $field_data['hide']);
      $entity->setClassifiableFieldForce($field_name, (bool) $field_data['force']);
    }

    // Save fillable fields.
    $fillable_fields = $form_state->getValue(['fillable', 'fields']);
    foreach ($fillable_fields as $field_name => $field_data) {
      if (empty($field_data['enabled'])) {
        continue;
      }
      $properties = $field_data['properties'] ?? [];
      $properties = is_string($properties) ? [$properties] : $properties;
      $properties = array_filter($properties);
      if (empty($properties)) {
        continue;
      }
      $entity->setFillableFieldEnabled($field_name, (bool) $field_data['enabled']);
      $entity->setFillableFieldProperties($field_name, $properties);
      $entity->setFillableFieldHide($field_name, (bool) $field_data['hide']);
      $entity->setFillableFieldForce($field_name, (bool) $field_data['force']);
    }
  }

  /**
   * Get the analyzable fields for the supported entity type and bundle.
   *
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   *
   * @return array<string, string>
   *   An array of field names and labels.
   */
  protected function getAnalyzableFields(ClassificationWorkflowInterface $workflow): array {
    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();

    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    $allowed_types = ['text', 'text_long', 'text_with_summary', 'reliefweb_file'];
    $allowed_fields = [$entity_type->getKey('label')];
    // @todo retrieve that from some config.
    $disallowed_fields = [];

    $analyzable_fields = [];
    foreach ($fields as $field_name => $field) {
      if (in_array($field_name, $disallowed_fields)) {
        continue;
      }
      if (in_array($field->getType(), $allowed_types) || in_array($field_name, $allowed_fields)) {
        $analyzable_fields[$field_name] = $field->getLabel();
      }
    }

    // Allow other modules to change the allowed analyzable fields.
    $this->moduleHandler->alter(
      'ocha_content_classification_analyzable_fields',
      $analyzable_fields,
      $workflow
    );

    return array_filter($analyzable_fields);
  }

  /**
   * Get taxonomy term reference fields.
   *
   * Note: this only works well for fields that reference a single vocabulary.
   *
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   *
   * @return array<string, array<string, string>>
   *   An associative array of field information with field names as keys and
   *   with associative arrays with label and vocabulary properties as values.
   */
  protected function getTaxonomyTermReferenceFields(ClassificationWorkflowInterface $workflow): array {
    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();

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
            'min' => $field->isRequired() ? 1 : 0,
            'max' => $field->getFieldStorageDefinition()->getCardinality() ?? -1,
          ];
        }
      }
    }
    return $term_fields;
  }

  /**
   * Get the analyzable fields for the supported entity type and bundle.
   *
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   *
   * @return array
   *   An associative array of field names as keys. Each item has the following
   *   properties:
   *   - label (string): field label.
   *   - properties (array): optional associative array of field properties,
   *     keyed by property with property labels as values.
   */
  protected function getFillableFields(ClassificationWorkflowInterface $workflow): array {
    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();

    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    $allowed_types = ['text', 'text_long', 'text_with_summary'];
    $allowed_fields = [$entity_type->getKey('label')];
    // @todo retrieve that from some config.
    $disallowed_fields = [];

    $fillable_fields = [];
    foreach ($fields as $field_name => $field) {
      if (in_array($field_name, $disallowed_fields)) {
        continue;
      }
      if (in_array($field->getType(), $allowed_types) || in_array($field_name, $allowed_fields)) {
        $fillable_fields[$field_name]['label'] = $field->getLabel();

        // Get the list of properties.
        foreach ($field->getFieldStorageDefinition()->getPropertyDefinitions() as $property => $property_definition) {
          if (
            $property_definition->isComputed() ||
            $property_definition->isInternal() ||
            $property_definition->isReadOnly() ||
            $property_definition->getDataType() !== 'string'
          ) {
            continue;
          }
          $fillable_fields[$field_name]['properties'][$property] = $property_definition->getLabel();
        }
      }
    }

    // Allow other modules to change the allowed analyzable fields.
    $this->moduleHandler->alter(
      'ocha_content_classification_fillable_fields',
      $fillable_fields,
      $workflow
    );

    return array_filter($fillable_fields);
  }

}
