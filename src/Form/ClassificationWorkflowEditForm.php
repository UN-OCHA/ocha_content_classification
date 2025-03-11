<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the Classification Workflow edit form.
 */
class ClassificationWorkflowEditForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow Label'),
      '#default_value' => $workflow->label(),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Classification attempts limit'),
      '#default_value' => $workflow->getAttemptsLimit(),
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Maximum number of attempts before marking the classification as failed.'),
      '#required' => TRUE,
    ];

    $form['validation'] = [
      '#type' => 'details',
      '#title' => $this->t('Validation'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('List of validation checks to perform to decide if an entity can be classified.'),
    ];
    $form['validation']['empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fields must be empty'),
      '#default_value' => $workflow->getValidationCheck('empty'),
      '#description' => $this->t('Skip the entire classification if any of the classifiable or fillable field already has a value.'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $workflow->status(),
    ];

    return $form;
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
    $label = $form_state->getValue(['label'], $entity->id());
    $limit = $form_state->getValue(['limit'], 1);
    $validation = $form_state->getValue(['validation']) ?: [];
    $status = $form_state->getValue(['status']);

    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $entity */
    $entity->setLabel($label);
    $entity->setAttemptsLimit((int) $limit);
    $entity->setValidationChecks($validation);
    $entity->setStatus(!empty($status));
  }

}
