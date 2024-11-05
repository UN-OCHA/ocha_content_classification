<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form for deleting a Classification Workflow entity.
 */
class ClassificationWorkflowDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string|MarkupInterface {
    return $this->t('Are you sure you want to delete the classification workflow %name?', [
      '%name' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('entity.ocha_classification_workflow.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string|MarkupInterface {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $entity */
    $entity = $this->entity;
    $entity->delete();

    $this->messenger()->addStatus($this->t('The classification workflow %label has been deleted.', [
      '%label' => $entity->label(),
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
