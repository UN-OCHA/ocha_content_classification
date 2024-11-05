<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for selecting the classifier and setting its configuration.
 */
class ClassificationWorkflowClassifierForm extends EntityForm {

  /**
   * Constructs a ClassificationWorkflowClassifierForm object.
   *
   * @param \Drupal\ocha_content_classification\Plugin\ClassifierPluginManagerInterface $classifierPluginManager
   *   The classifier plugin manager.
   */
  public function __construct(
    protected ClassifierPluginManagerInterface $classifierPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.ocha_content_classification.classifier')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    // Classifier selection.
    $form['classifier'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Classifier'),
      '#tree' => TRUE,
    ];

    $classifier_options = [];
    foreach ($this->classifierPluginManager->getDefinitions() as $plugin_id => $definition) {
      $classifier_options[$plugin_id] = $definition['label'];
    }

    // Classifier plugin selection.
    $form['classifier']['id'] = [
      '#type' => 'select',
      '#title' => $this->t('Plugin'),
      '#options' => $classifier_options,
      '#default_value' => $workflow->getClassifierPluginId(),
      '#ajax' => [
        'callback' => '::updateClassifierSettings',
        'wrapper' => 'classifier-settings-wrapper',
      ],
      '#required' => TRUE,
    ];

    // Container for classifier settings.
    $form['classifier']['settings'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'classifier-settings-wrapper'],
    ];

    // Load the selected classifier settings.
    $classifier_plugin = $workflow->getClassifierPlugin();
    if (!empty($classifier_plugin)) {
      $form['classifier']['settings'] += $classifier_plugin->buildConfigurationForm([], $form_state, $workflow);
    }

    return $form;
  }

  /**
   * Ajax callback to update classifier settings based on selected plugin.
   */
  public function updateClassifierSettings(array &$form, FormStateInterface $form_state): array {
    return $form['classifier']['settings'];
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    // This form is only to edit the classifier settings.
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
    // Reset the classifier settings.
    $entity->set('classifier', []);

    // Save the selected classifier plugin ID.
    $plugin_id = $form_state->getValue(['classifier', 'id']);
    if (!empty($plugin_id) && $this->classifierPluginManager->hasDefinition($plugin_id)) {
      $plugin_settings = $form_state->getValue(['classifier', 'settings']);
      $plugin = $this->classifierPluginManager->createInstance($plugin_id, $plugin_settings);

      $entity->setClassifierPluginId($plugin_id);
      $entity->setClassifierPluginSettings($plugin->getConfiguration());
    }
  }

}
