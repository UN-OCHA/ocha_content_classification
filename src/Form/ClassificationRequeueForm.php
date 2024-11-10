<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for requeueing and entity for classification.
 */
class ClassificationRequeueForm extends EntityConfirmFormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface $contentEntityClassifier
   *   The content entity classifier service.
   */
  public function __construct(
    // Compatibility with EntityConfirmFormBase.
    EntityTypeManagerInterface $entity_type_manager,
    protected ContentEntityClassifierInterface $contentEntityClassifier,
  ) {
    $this->setEntityTypeManager($entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ocha_content_classification.content_entity_classifier')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string|MarkupInterface {
    return $this->t('Are you sure you want to requeue this entity for classification?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    // Canonical URL.
    return $this->entity->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string|MarkupInterface {
    return $this->t('Requeue for classification');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->entity;

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    // Requeue the entity for classification.
    if ($this->contentEntityClassifier->requeueEntity($entity)) {
      $this->messenger()->addMessage($this->t('The @bundle_label @entity_id has been requeued for classification.', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('The @bundle_label @entity_id could not be requeued for classification.', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
      ]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // Nothing to do since we do not modify the entity in that form.
  }

}
