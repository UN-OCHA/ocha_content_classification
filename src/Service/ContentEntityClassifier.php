<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Psr\Log\LoggerInterface;

/**
 * Service to handle content entity classification.
 */
class ContentEntityClassifier implements ContentEntityClassifierInterface {

  /**
   * The logger for the service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Temporary store information about an entity mandatory fields.
   *
   * @var array
   */
  protected array $mandatoryFields = [];

  /**
   * Cache loaded workfows for entities.
   *
   * @var array
   */
  protected array $workflows = [];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isEntityClassifiable(EntityInterface $entity, bool $check_status = TRUE): bool {
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return FALSE;
    }
    return $this->validateEntityForWorkflow($entity, $workflow, $check_status);
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowForEntity(EntityInterface $entity): ?ClassificationWorkflowInterface {
    if (!($entity instanceof ContentEntityInterface)) {
      return NULL;
    }

    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    if (!isset($this->workflows[$entity_type_id][$bundle])) {
      $workflows = $this->entityTypeManager
        ->getStorage('ocha_classification_workflow')
        ->loadByProperties([
          'target.entity_type_id' => $entity_type_id,
          'target.bundle' => $bundle,
          'status' => 1,
        ]);

      // This stores FALSE if workflows is empty.
      $this->workflows[$entity_type_id][$bundle] = reset($workflows);
    }

    $workflow = $this->workflows[$entity_type_id][$bundle] ?? FALSE;

    return $workflow ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function queueEntity(EntityInterface $entity, bool $requeue = FALSE): bool {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return FALSE;
    }

    // If explicitly asked to requeue, remove any classification progress record
    // for the entity.
    if ($requeue) {
      $workflow->deleteClassificationProgress($entity);
    }
    // Otherwise, if there is a progress record then it means the entity is
    // already queued, has been processed or the classification failed. In any
    // case we don't need to add back the entity to the queue because the life
    // cycle of the item in the queue is managed by the queue wroker. It is
    // in charge of keeping items in the queue if they haven't yet been
    // processed properly.
    // @todo Do we want to update the revision message to indicate the entity
    // is still being queued for classification for example?
    elseif ($workflow->getClassificationProgress($entity) !== NULL) {
      return FALSE;
    }

    // Validate the entity. No need to queue an entity that cannot be processed.
    if (!$this->validateEntityForWorkflow($entity, $workflow)) {
      return FALSE;
    }

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    $message = strtr('@bundle_label @entity_id queued for classification.', [
      '@bundle_label' => $bundle_label,
      '@entity_id' => $entity->id(),
    ]);

    // Get the queue corresponding to the worflow ('base_id:derivative_id').
    $queue_name = 'ocha_classification_workflow:' . $workflow->id();

    $item = [
      'entity_type_id' => $entity->getEntityTypeId(),
      'entity_bundle' => $entity->bundle(),
      'entity_id' => $entity->id(),
    ];

    // Queue the entity.
    if (!$this->queueFactory->get($queue_name, TRUE)->createItem($item)) {
      $this->getLogger()->error('Unable to queue @bundle_label @entity_id.', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
      ]);
      return FALSE;
    }

    // Create a classification progress record for the entity.
    $workflow->updateClassificationProgress($entity, $message, 'queued', TRUE);

    // Add a revision message to the entity.
    if ($entity instanceof RevisionLogInterface) {
      // Only add the message if not already in the current revision message.
      $revision_message = $entity->getRevisionLogMessage() ?? '';
      if (mb_strpos($revision_message, $message) === FALSE) {
        $message = trim($revision_message . ' ' . $message);
        $entity->setRevisionLogMessage($message);
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function entityBeforeSave(EntityInterface $entity): void {
    // Skip if the user is not allowed to use the automated classification.
    if (!$this->currentUser->hasPermission('apply ocha content classification')) {
      return;
    }

    // Skip if the user can bypass the automated classification.
    if ($this->currentUser->hasPermission('bypass ocha content classification')) {
      return;
    }

    // Skip if there is no enabled workflow to process this entity.
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Skip if the entity is not valid, for example because it has already
    // been processed or the automated classification failed.
    if (!$this->validateEntityForWorkflow($entity, $workflow)) {
      return;
    }

    // Temporary make mandatory fields non-mandatory.
    foreach ($workflow->getEnabledClassifiableFields() as $field_name => $field_info) {
      // Non mandatory field.
      if (empty($field_info['min'])) {
        continue;
      }

      // Missing field.
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_definition = $entity->get($field_name)->getFieldDefinition();

      if ($field_definition->isRequired()) {
        // Make the field temporary not mandatory so the entity can be saved.
        $field_definition->setRequired(FALSE);
        // Store a reference to the field so we can reset is requirement status.
        $this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->id()][$field_name] = $field_name;
      }
    }

    // Queue the entity for classification.
    $this->queueEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityAfterSave(EntityInterface $entity): void {
    // Skip if the user is not allowed to use the automated classification.
    if (!$this->currentUser->hasPermission('apply ocha content classification')) {
      return;
    }

    // Skip if the user can bypass the automated classification.
    if ($this->currentUser->hasPermission('bypass ocha content classification')) {
      return;
    }

    // Skip if there is no enabled workflow to process this entity.
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Skip of the entity is not valid, for example because it has already
    // been processed or the automated classification failed.
    if (!$this->validateEntityForWorkflow($entity, $workflow)) {
      return;
    }

    // Restore the field requirements.
    if (isset($this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->id()])) {
      foreach ($this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->id()] as $field_name) {
        $entity->get($field_name)->getFieldDefinition()->setRequired(TRUE);
      }
      unset($this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityDelete(EntityInterface $entity): void {
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Remove the classification progress record for the entity.
    $workflow->deleteClassificationProgress($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state?->getFormObject();
    if (!($form_object instanceof ContentEntityFormInterface)) {
      return;
    }

    // Skip if this is not a form for a content entity.
    $entity = $form_object?->getEntity();
    if (empty($entity) || !($entity instanceof ContentEntityInterface)) {
      return;
    }

    // Skip if the user is not allowed to use the automated classification.
    if (!$this->currentUser->hasPermission('apply ocha content classification')) {
      return;
    }

    // Skip if the user can bypass the automated classification.
    if ($this->currentUser->hasPermission('bypass ocha content classification')) {
      return;
    }

    // Skip if there is no enabled workflow to process this entity.
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Skip of the entity is not valid, for example because it has already
    // been processed or the automated classification failed.
    if (!$this->validateEntityForWorkflow($entity, $workflow)) {
      return;
    }

    // Hide classifiable fields since they will be populated automatically.
    // @todo show a message indicating the automatic tagging instead of
    // fully hiding them.
    foreach ($workflow->getEnabledClassifiableFields() as $field_name => $field_info) {
      if ($entity->hasField($field_name) && $entity->get($field_name)->isEmpty()) {
        $form[$field_name]['#access'] = FALSE;
        $form[$field_name]['#required'] = FALSE;
      }
    }
  }

  /**
   * Validate an entity for the given workflow.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to validate.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   * @param bool $check_status
   *   Whether to check the classification record status or not. This is used
   *   notably to allow requeueing entities after reverting to a revision where
   *   the classifiable fields are empty for example.
   *
   * @return bool
   *   TRUE if the entity cab be processed by the workflow.
   */
  protected function validateEntityForWorkflow(EntityInterface $entity, ClassificationWorkflowInterface $workflow, bool $check_status = TRUE): bool {
    if (!($entity instanceof ContentEntityInterface)) {
      return FALSE;
    }
    try {
      return $workflow->validateEntity($entity, $check_status);
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

  /**
   * Get the plugin logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger.
   */
  protected function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->logger = $this->loggerFactory->get('ocha_content_classifier');
    }
    return $this->logger;
  }

}
