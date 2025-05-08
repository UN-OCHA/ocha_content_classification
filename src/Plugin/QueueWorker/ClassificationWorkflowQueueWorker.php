<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\QueueWorker;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Enum\ClassificationMessage;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Exception\AttemptsLimitReachedException;
use Drupal\ocha_content_classification\Exception\ClassificationCompletedException;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Exception\ClassificationSkippedException;
use Drupal\ocha_content_classification\Exception\FieldAlreadySpecifiedException;
use Drupal\ocha_content_classification\Exception\UnexpectedValueException;
use Drupal\ocha_content_classification\Exception\UnsupportedEntityException;
use Drupal\ocha_content_classification\helper\EntityHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class for the classification workflow queue workers.
 *
 * @QueueWorker(
 *   id = "ocha_classification_workflow",
 *   title = @Translation("Classification Workflow Queue Worker"),
 *   cron = {"time" = 30},
 *   deriver = "Drupal\ocha_content_classification\Plugin\Derivative\ClassificationWorkflowQueueWorkerDeriver"
 * )
 */
class ClassificationWorkflowQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(mixed $data) {
    // Skip and remove the item from the queue is there is no classification
    // workflow to process it.
    $workflow = $this->getClassificationWorkflow();
    if (empty($workflow) || !$workflow->enabled()) {
      $this->getLogger()->error('Classification workflow not found or not enabled.');
      return;
    }

    // Check if the workflow has a classifier properly configured. If not, we
    // can stop the entire queue processing until fixed without trying to load
    // the entity.
    if ($workflow->getClassifierPlugin() === NULL) {
      throw new SuspendQueueException('Classifier plugin not found for workflow: @workflow', [
        '@workflow' => $workflow->label(),
      ]);
    }

    // Load the entity for the queued data.
    $entity = $this->loadEntity($data);
    if (empty($entity)) {
      $this->getLogger()->error('Missing or unsupported queued entity.');
      return;
    }

    try {
      $this->classifyEntity($entity, $workflow);
    }
    // Skip and remove the entity from the queue if unsupported.
    catch (UnsupportedEntityException $exception) {
      $this->getLogger()->error($exception->getMessage());
    }
    // Skip and remove the entity from the queue if it should not be classified.
    catch (ClassificationSkippedException $exception) {
      $this->getLogger()->error($exception->getMessage());
    }
    // Skip and remove the entity from the queue if already completed.
    catch (ClassificationCompletedException $exception) {
      $this->handleClassificationCompletedException($entity, $workflow, $exception);
    }
    // Skip and remove the entity from the queue if the classification failed.
    catch (ClassificationFailedException $exception) {
      $this->handleClassificationFailedException($entity, $workflow, $exception);
    }
    // An unexpected value is when the classifier doesn't return the expected
    // output or not in the expected format. We only skip the processing for
    // this item but we keep it in the queue to try at another time.
    // The AI output is not fully deterministic even with a temperature of 0.0
    // so later attempts may result in correct output.
    catch (UnexpectedValueException $exception) {
      $this->handleUnexpectedValueException($entity, $workflow, $exception);
    }
    // For other exceptions, like invalid configuration we stop the entire
    // queue processing but let the item in the queue for when the issue is
    // solved.
    catch (\Exception $exception) {
      $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

      $this->getLogger()->error(strtr('Error while classifying @bundle_label @entity_id: @error', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
        '@error' => $exception->getMessage(),
      ]));

      throw new SuspendQueueException($exception->getMessage());
    }
  }

  /**
   * Classify the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification worklfow.
   *
   * @throws \Exception|\Drupal\ocha_content_classification\Exception\ExceptionInterface $exception
   *   An exception if the classification didn't go as expected.
   */
  protected function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): void {
    // This throws an exception in case of failure and returns the list of the
    // fields updated during the classification otherwise.
    $updated_fields = $workflow->classifyEntity($entity);

    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    $this->getLogger()->info(strtr('Classification successful for @bundle_label @entity_id.', [
      '@bundle_label' => $bundle_label,
      '@entity_id' => $entity->id(),
    ]));

    // Mark the entity as processed.
    $this->updateClassificationStatus($entity, $workflow, ClassificationMessage::Completed, ClassificationStatus::Completed, $updated_fields);
  }

  /**
   * Handle already processed exceptions.
   *
   * This happens when the classification was already marked as processed in the
   * entity classification progress record or the classifiable fields have been
   * specified otherwise (ex: manually by people with the bypass classification
   * permission).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification worklfow.
   * @param \Drupal\ocha_content_classification\Exception\ClassificationCompletedException $exception
   *   The exception.
   */
  protected function handleClassificationCompletedException(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ClassificationCompletedException $exception,
  ): void {
    $this->getLogger()->notice($exception->getMessage());

    if ($exception instanceof FieldAlreadySpecifiedException) {
      $message = ClassificationMessage::FieldsAlreadySpecified;
    }
    else {
      $message = ClassificationMessage::Completed;
    }

    // Ensure the classification progress record reflects the status.
    $this->updateClassificationStatus($entity, $workflow, $message, ClassificationStatus::Completed);
  }

  /**
   * Handle classification failed exceptions.
   *
   * This happens when the classification was already marked as failed or the
   * attempts limit was reached in the entity classification progress record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification worklfow.
   * @param \Drupal\ocha_content_classification\Exception\ClassificationFailedException $exception
   *   The exception.
   */
  protected function handleClassificationFailedException(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ClassificationFailedException $exception,
  ): void {
    $this->getLogger()->warning($exception->getMessage());

    if ($exception instanceof AttemptsLimitReachedException) {
      $message = ClassificationMessage::AttemptsLimitReached;
    }
    else {
      $message = ClassificationMessage::Failed;
    }

    // Ensure the classification progress record reflects the status.
    $this->updateClassificationStatus($entity, $workflow, $message, ClassificationStatus::Failed);
  }

  /**
   * Handle unexpected value exceptions.
   *
   * This happens when the classifier fails to classify the entity for temporary
   * reasons like receiving an empty output from an AI etc. If the attempts
   * limit has been reached we consider it a definitive failure otherwise we
   * just increase the attempts count to try again later.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification worklfow.
   * @param \Drupal\ocha_content_classification\Exception\UnexpectedValueException $exception
   *   The exception.
   *
   * @throws \Drupal\ocha_content_classification\Exception\UnexpectedValueException
   *   Rethrow the exception in case of temporary failure to keep the item in
   *   the queue so that it can be processed again later.
   */
  protected function handleUnexpectedValueException(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    UnexpectedValueException $exception,
  ): void {
    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    // Retrieve how many classification attempts have already been made.
    $existing_record = $workflow->getClassificationProgress($entity);
    $attempts = $existing_record['attempts'] ?? 0;

    // Mark as failed if the entity has reached the classification attempts
    // limit.
    if ($attempts >= $workflow->getAttemptsLimit()) {
      $this->getLogger()->error(strtr('Classification failure for @bundle_label @entity_id: @error', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
        '@error' => $exception->getMessage(),
      ]));

      $this->updateClassificationStatus($entity, $workflow, ClassificationMessage::AttemptsLimitReached, ClassificationStatus::Failed);
    }
    // Otherwise update the progress record to increment the attempts number,
    // without creating a new revision for the entity since this is temporary.
    // Throw an exception to keep the item in the queue.
    else {
      $this->getLogger()->error(strtr('Temporary classification failure for @bundle_label @entity_id: @error', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
        '@error' => $exception->getMessage(),
      ]));

      // Keep the status as queued but update the progress record attempts.
      $workflow->updateClassificationProgress($entity, ClassificationMessage::FailedTemporarily, ClassificationStatus::Queued);

      // Rethrow the exception to keep the item in the queue so it can be
      // processed again later on.
      throw $exception;
    }
  }

  /**
   * Load an entity from the queued data.
   *
   * @param mixed $data
   *   Queued data.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The loaded entity.
   */
  protected function loadEntity(mixed $data): ?ContentEntityInterface {
    if (!is_array($data) && !isset($data['entity_type_id'], $data['entity_bundle'], $data['entity_id'])) {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage($data['entity_type_id'])->load($data['entity_id']);
    if (!($entity instanceof ContentEntityInterface) || $entity->bundle() !== $data['entity_bundle']) {
      return NULL;
    }

    return $entity;
  }

  /**
   * Update the classication status for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param \Drupal\ocha_content_classification\enum\ClassificationMessage $message
   *   A message (ex: error).
   * @param \Drupal\ocha_content_classification\enum\ClassificationStatus $status
   *   The classification status (queued, processed or failed).
   * @param ?array $updated_fields
   *   List of updated fields during the classification.
   */
  protected function updateClassificationStatus(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ClassificationMessage $message,
    ClassificationStatus $status,
    ?array $updated_fields = NULL,
  ): void {
    $existing_record = $workflow->getClassificationProgress($entity);
    $existing_status = $existing_record['status'] ?? NULL;

    // Save the changes to the entity if the classification status changed, for
    // example from queued to processed.
    if ($existing_status !== $status) {
      $this->saveEntity($entity, $message, $status, $existing_record['user_id'] ?? NULL);
    }

    // Update the classification progress record with the new status and
    // message.
    $workflow->updateClassificationProgress($entity, $message, $status, updated_fields: $updated_fields);
  }

  /**
   * Save an entity with the changes from the classification.
   *
   * This will create a new revision if supported.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   * @param \Drupal\ocha_content_classification\enum\ClassificationMessage $message
   *   Revision message.
   * @param \Drupal\ocha_content_classification\enum\ClassificationStatus $status
   *   The classification status (queued, processed or failed).
   * @param ?int $user_id
   *   The ID of the user who initiated the classification.
   */
  protected function saveEntity(
    ContentEntityInterface $entity,
    ClassificationMessage $message,
    ClassificationStatus $status,
    ?int $user_id = NULL,
  ): void {
    // Add a flag to indicate the classification proceeded, with its status.
    // This is to allow other modules to act on an entity being updated after
    // the automated classification.
    $entity->ocha_content_classification_status = $status;

    if ($entity instanceof RevisionLogInterface) {
      // If there is a user ID (and it's not anonymous = 0), use it as revision
      // user ID, otherwise let Drupal chose (previous revision user, owner or
      // current user).
      if (!empty($user_id)) {
        $entity->setRevisionUserId($user_id);
      }
      $entity->setRevisionCreationTime(time());

      // Append the message to the previous revision log message, after removing
      // old classification messages so that revision information not related to
      // the classification are not lost.
      $revision_log = (string) ($entity->getRevisionLogMessage() ?? '');
      $revision_log = ClassificationMessage::addClassificationMessage($revision_log, $message);

      $entity->setRevisionLogMessage($revision_log);
    }
    $entity->setNewRevision(TRUE);
    $entity->save();
  }

  /**
   * Get the classification workflow associated with this queue worker.
   *
   * @return ?\Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface
   *   The workflow or NULL if none was found.
   */
  protected function getClassificationWorkflow(): ?ClassificationWorkflowInterface {
    return $this->entityTypeManager
      ->getStorage('ocha_classification_workflow')
      ->load($this->getDerivativeId());
  }

  /**
   * Get the plugin logger.
   *
   * @return Psr\Log\LoggerInterface
   *   Logger.
   */
  protected function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->logger = $this->loggerFactory->get(implode('.', [
        'ocha_content_classification',
        'queue_worker',
        $this->getDerivativeId(),
      ]));
    }
    return $this->logger;
  }

}
