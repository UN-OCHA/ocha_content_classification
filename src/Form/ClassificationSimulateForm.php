<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to run a dry-run classification and compare results to stored values.
 */
class ClassificationSimulateForm extends FormBase {

  /**
   * Constructs a ClassificationSimulateForm.
   */
  public function __construct(
    protected ContentEntityClassifierInterface $contentEntityClassifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ocha_content_classification.content_entity_classifier'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_content_classification_simulate_form';
  }

  /**
   * Builds a short line with the entity title for page context.
   */
  protected function buildEntityReferenceElement(ContentEntityInterface $entity): array {
    $title_text = $entity->label();
    if ($title_text === '' || $title_text === NULL) {
      $title_text = (string) $this->t('@type ID @id', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => (string) ($entity->id() ?? $this->t('unsaved')),
      ]);
    }

    return [
      '#markup' => '<p class="ocha-content-classification-simulate-entity-title"><strong>' . Html::escape((string) $this->t('Title')) . ':</strong> ' . Html::escape((string) $title_text) . '</p>',
    ];
  }

  /**
   * Gets the entity from the route.
   */
  protected function getContentEntityFromRoute(): ?ContentEntityInterface {
    $route_match = $this->getRouteMatch();
    $entity_type_id = $route_match->getRouteObject()?->getDefault('entity_type');
    if (empty($entity_type_id)) {
      return NULL;
    }
    $entity = $route_match->getParameter($entity_type_id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entity = $this->getContentEntityFromRoute();
    if ($entity === NULL) {
      return [
        'error' => [
          '#markup' => '<p>' . $this->t('Entity not found.') . '</p>',
        ],
      ];
    }

    $result = $form_state->get('simulation_result');
    if ($result !== NULL) {
      return $this->buildResultsForm($form, $form_state, $entity, $result);
    }

    $form['entity_context'] = $this->buildEntityReferenceElement($entity);

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    $form['description'] = [
      '#markup' => '<p>' . $this->t(
        'Run the same classification process used in production on a copy of this @bundle. Classifiable and fillable fields are cleared on the copy before inference. Nothing is saved to the database. This calls the real model and may take some time and incur usage costs.',
        ['@bundle' => $bundle_label],
      ) . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run simulation'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $entity->toUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * Builds the results view after the simulation has run.
   */
  protected function buildResultsForm(array $form, FormStateInterface $form_state, ContentEntityInterface $entity, array $result): array {
    $form['entity_context'] = $this->buildEntityReferenceElement($entity);

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Comparison of stored values on this content and the simulated classification output.') . '</p>',
    ];

    if (empty($result['success'])) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('Simulation failed: @message', [
          '@message' => $result['error'] ?? $this->t('Unknown error.'),
        ]) . '</div>',
      ];
    }
    else {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $simulated */
      $simulated = $result['simulated'];
      $workflow = $this->contentEntityClassifier->getWorkflowForEntity($entity);
      $field_names = array_unique(array_merge(
        $result['updated_fields'] ?? [],
        array_keys($workflow?->getEnabledFields(['classifiable', 'fillable']) ?? []),
      ));
      sort($field_names);

      $rows = [];
      foreach ($field_names as $field_name) {
        if (!$entity->hasField($field_name) || !$simulated->hasField($field_name)) {
          continue;
        }
        $label = $entity->get($field_name)->getFieldDefinition()->getLabel();
        $rows[] = [
          $label,
          [
            'data' => $entity->get($field_name)->view([
              'type' => 'default',
              'label' => 'hidden',
            ]),
          ],
          [
            'data' => $simulated->get($field_name)->view([
              'type' => 'default',
              'label' => 'hidden',
            ]),
          ],
        ];
      }

      $form['comparison'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Field'),
          $this->t('Stored on content'),
          $this->t('Simulated'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No classifiable or fillable fields to compare.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['again'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run again'),
      '#submit' => ['::resetSimulation'],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to content'),
      '#url' => $entity->toUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * Clears stored results so the user can run another simulation.
   */
  public function resetSimulation(array &$form, FormStateInterface $form_state): void {
    $form_state->set('simulation_result', NULL);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity = $this->getContentEntityFromRoute();
    if ($entity === NULL) {
      return;
    }

    $result = $this->contentEntityClassifier->simulateClassification($entity);
    $form_state->set('simulation_result', $result);
    $form_state->setRebuild();
  }

}
