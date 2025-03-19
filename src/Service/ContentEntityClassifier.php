<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Enum\ClassificationMessage;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Exception\ClassificationCompletedException;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\user\EntityOwnerInterface;
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
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The queue factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected ModuleHandlerInterface $moduleHandler,
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

      // This stores FALSE if $workflows is empty.
      $this->workflows[$entity_type_id][$bundle] = reset($workflows);
    }

    $workflow = $this->workflows[$entity_type_id][$bundle] ?? FALSE;

    return $workflow ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function requeueEntity(ContentEntityInterface $entity): bool {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return FALSE;
    }

    // Check if the entity is valid but do not check the classification status
    // since we will reset it if valid.
    if (!$this->validateEntityForWorkflow($entity, $workflow, FALSE)) {
      return FALSE;
    }

    // Create a new revision.
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionUserId($this->currentUser->id());
      $entity->setRevisionCreationTime(time());
      $this->addEntityQueuedRevisionMessage($entity, TRUE);
    }
    $entity->setNewRevision(TRUE);
    $entity->save();

    // Delete any existing classification record so we can requeue the entity.
    $workflow->deleteClassificationProgress($entity);

    // Queue the entity.
    return $this->queueEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityBeforeSave(EntityInterface $entity): void {
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // If the entity is being reverted to a revision that can be queued, we
    // clear any classification progress record so that the entity can be queued
    // again.
    $this->handleEntityBeingReverted($entity);

    // Check the permission to use the automated classification.
    if (!$this->checkUserPermissions($entity)) {
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
        // Make the field temporarily not mandatory so the entity can be saved.
        $field_definition->setRequired(FALSE);
        // Store a reference to the field so we can reset is requirement status.
        $this->storeMandatoryField($entity, $field_name);
      }
    }

    // Add a revision message if the entity can be queued for classification.
    // The actual queueing is done in the ::entityAfterSave() since we need an
    // entity ID to insert the classification record which is not yet present
    // when the entity is new.
    //
    // Note: in the rare case that adding an item to the queue fails, we may
    // end up with a revision message saying the entity is queued while it is
    // not. We don't have much choice here, because the revision log message
    // needs to be set before the entity is saved and the entity needs to be
    // queued after it is saved so that it has an entity ID notably, if new.
    //
    // @todo If Drupal ever introduces a hook for when the entity is fully saved
    // (not like hook_entity_insert() or hook_entity_update() which happen while
    // still in the database transaction). Then we could use that hook to queue
    // the entity and resave it with the new revision message.
    //
    // @todo Do we want to update the revision message to indicate the entity
    // is still being queued for classification for example?
    if ($this->canEntityBeQueued($entity)) {
      $this->addEntityQueuedRevisionMessage($entity);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityAfterSave(EntityInterface $entity): void {
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // Check the permission to use the automated classification.
    if (!$this->checkUserPermissions($entity)) {
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

    // Restore the field requirements.
    foreach ($this->getMandatoryFields($entity) as $field_name) {
      $entity->get($field_name)->getFieldDefinition()->setRequired(TRUE);
    }
    $this->clearMandatoryFields($entity);

    // Queue the entity for classification if not already.
    $this->queueEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityDelete(EntityInterface $entity): void {
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

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

    // Check the permission to use the automated classification.
    if (!$this->checkUserPermissions($entity)) {
      return;
    }

    // Skip if there is no enabled workflow to process this entity.
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Skip if the workflow cannot classify the entity.
    //
    // @todo How to handle the case where the classification failed? Should we
    // show the field then to at least give a chance to the person editing the
    // entity to select some values? That may be difficult to understand.
    try {
      $workflow->validateEntity($entity);
    }
    catch (ClassificationCompletedException | ClassificationFailedException $exception) {
      // Nothing to do, those are valid exceptions and we hide the fields
      // regardless.
    }
    catch (\Exception $exception) {
      // For other exceptions, we do not modify the form and show the fields
      // since that may be because the entity is not supported, invalid or
      // there is some configuration issue preventing the classification.
      return;
    }

    // Hide classifiable and fillable fields since they will be populated
    // automatically.
    //
    // @todo show a message indicating the automatic tagging instead of
    // fully hiding them?
    foreach ($workflow->getEnabledClassifiableFields() as $field_name => $field_info) {
      if ($entity->hasField($field_name) && $workflow->getClassifiableFieldHide($field_name)) {
        $form[$field_name]['#access'] = FALSE;
        $form[$field_name]['#required'] = FALSE;
      }
    }
    foreach ($workflow->getEnabledFillableFields() as $field_name => $field_info) {
      if ($entity->hasField($field_name) && $workflow->getFillableFieldHide($field_name)) {
        $form[$field_name]['#access'] = FALSE;
        $form[$field_name]['#required'] = FALSE;
      }
    }
  }

  /**
   * Handle an entity being reverted.
   *
   * Reset the classification record if necessary so that the entity can be
   * requeued.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being possibly reverted.
   *
   * @todo Consider requeuing the entity based on the revision user's
   * permissions rather than the current user's. If a user with "bypass
   * classification" permission or without "apply classification" permission
   * reverts the entity to a queueable revision, it will not be queued.
   * However, it could be queued if the revision user had the necessary rights.
   */
  protected function handleEntityBeingReverted(ContentEntityInterface $entity): void {
    if (!isset($entity->original)) {
      return;
    }

    $revision_id = $entity->getLoadedRevisionId();
    $original_revision_id = $entity->original->getLoadedRevisionId();

    // Check if the entity is being reverted in which case its loaded revision
    // ID will be lower than the original version.
    if (empty($revision_id) || empty($original_revision_id) || $revision_id >= $original_revision_id) {
      return;
    }

    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Validate the entity without checking the classification status, skip if
    // the entity cannot be queued.
    if (!$this->validateEntityForWorkflow($entity, $workflow, FALSE)) {
      return;
    }

    // Delete any existing classification record so the entity can be requeued,
    // either via the UI or via normal editing workflow by someone with the
    // rights to apply the automated classification.
    $workflow->deleteClassificationProgress($entity);
  }

  /**
   * Add a mandatory field to the tracking array.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which to add the mandatory field.
   * @param string $fieldName
   *   The name of the field to add.
   */
  protected function storeMandatoryField(EntityInterface $entity, string $fieldName): void {
    $this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->uuid()][$fieldName] = $fieldName;
  }

  /**
   * Retrieve the mandatory fields for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which to retrieve mandatory fields.
   *
   * @return array
   *   An array of mandatory field names.
   */
  protected function getMandatoryFields(EntityInterface $entity): array {
    return $this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->uuid()] ?? [];
  }

  /**
   * Clear the mandatory fields for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which to clear mandatory fields.
   */
  protected function clearMandatoryFields(EntityInterface $entity): void {
    unset($this->mandatoryFields[$entity->getEntityTypeId()][$entity->bundle()][$entity->uuid()]);
  }

  /**
   * Check if an entity can be queued for classification.
   *
   * Note: this assumes that is has already been validated.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE if the entity is already queued or has been processed.
   */
  protected function canEntityBeQueued(EntityInterface $entity): bool {
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return FALSE;
    }

    // If there is a progress record then it means the entity is already queued,
    // has been processed or the classification failed. In any case we don't
    // need to add back the entity to the queue because the life cycle of the
    // item in the queue is managed by the queue wroker. It is in charge of
    // keeping items in the queue if they haven't yet been processed properly.
    return $workflow->getClassificationProgress($entity) === NULL;
  }

  /**
   * Queue an entity for classification.
   *
   * Note: this assumes that is has already been validated.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE if the entity was queued.
   */
  protected function queueEntity(EntityInterface $entity): bool {
    /** @var \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow */
    $workflow = $this->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return FALSE;
    }

    if (!$this->canEntityBeQueued($entity)) {
      return FALSE;
    }

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

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

    // Create a new classification progress record for the entity.
    $workflow->updateClassificationProgress($entity, ClassificationMessage::Queued, ClassificationStatus::Queued, TRUE);

    return TRUE;
  }

  /**
   * Add a revision message to indicate an entity is queued for classification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   * @param bool $replace
   *   If TRUE, replace the existing revision message otherwise, concatenate the
   *   entity queued message with the existing one.
   */
  protected function addEntityQueuedRevisionMessage(EntityInterface $entity, bool $replace = FALSE): void {
    if (!($entity instanceof RevisionLogInterface)) {
      return;
    }

    $message = ClassificationMessage::Queued->value;

    if (!$replace) {
      // Only add the message if not already in the current revision message.
      $revision_message = $entity->getRevisionLogMessage() ?? '';
      if (mb_strpos($revision_message, $message) === FALSE) {
        $message = trim($revision_message . ' ' . $message);
      }
      else {
        $message = $revision_message;
      }
    }

    $entity->setRevisionLogMessage($message);
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
   * Check if the user has the permissions to use the automated classification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to potentially classify.
   *
   * @return bool
   *   TRUE if the user is allowed to use the automated classification.
   */
  protected function checkUserPermissions(EntityInterface $entity): bool {
    // We need to check the revision user if defined.
    $account = $this->currentUser;
    if (!$entity->isNew()) {
      if ($entity instanceof RevisionLogInterface) {
        $account = $entity->getRevisionUser();
      }
      elseif ($entity instanceof EntityOwnerInterface) {
        $account = $entity->getOwner();
      }
    }

    if (empty($account)) {
      return FALSE;
    }

    // Let other modules decide if we should check the user permissions.
    $check_permissions = TRUE;
    $check_permissions_context = ['entity' => $entity];
    $this->moduleHandler->alter(
      'ocha_content_classification_user_permission_check',
      $check_permissions,
      $account,
      $check_permissions_context,
    );
    if (!$check_permissions) {
      return TRUE;
    }

    // Skip if the user is not allowed to use the automated classification.
    if (!$account->hasPermission('apply ocha content classification')) {
      return FALSE;
    }

    // Skip if the user can bypass the automated classification.
    if ($account->hasPermission('bypass ocha content classification')) {
      return FALSE;
    }

    return TRUE;
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
