<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\ocha_content_classification\Exception\AlreadyProcessedException;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Exception\UnexpectedValueException;
use Drupal\ocha_content_classification\Exception\UnsupportedEntityException;
use Drupal\ocha_content_classification\helper\EntityHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class for the classification workflow queue workers.
 *
 * @QueueWorker(
 *   id = "ocha_content_classification_workflow",
 *   title = @Translation("Classification Workflow Queue Worker"),
 *   cron = {"time" = 30},
 *   deriver = "Drupal\ocha_content_classification\Plugin\Derivative\ClassificationWorkflowQueueWorkerDeriver"
 * )
 */
class ClassificationWorkflowQueueWorker extends QueueWorkerBase {

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

    // Retrieve the label of the entity bundle.
    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    try {
      // Classify the entity.
      if ($workflow->classifyEntity($entity)) {
        $message = strtr('Classification successful for @bundle_label @entity_id.', [
          '@bundle_label' => $bundle_label,
          '@entity_id' => $entity->id(),
        ]);

        // Mark the entity as processed.
        $this->setPermanentStatus($entity, $workflow, $message, 'processed');

        $this->getLogger()->info('@bundle_label @entity_id updated with data from AI.', [
          '@bundle_label' => $bundle_label,
          '@entity_id' => $entity->id(),
        ]);
      }
    }
    // Skip and remove the entity from the queue if unsupported.
    catch (UnsupportedEntityException $exception) {
      $this->getLogger()->error($exception->getMessage());
    }
    // Skip and remove the entity from the queue if already processed.
    catch (AlreadyProcessedException $exception) {
      $this->getLogger()->notice($exception->getMessage());
      // Ensure the classification progress record reflects the status.
      $this->updateClassificationStatus($entity, $workflow, $exception->getMessage(), 'processed');
    }
    // Skip and remove the entity from the queue if the classification failed.
    catch (ClassificationFailedException $exception) {
      $this->getLogger()->warning($exception->getMessage());
      // Ensure the classification progress record reflects the status.
      $this->updateClassificationStatus($entity, $workflow, $exception->getMessage(), 'failed');
    }
    // An unexpected value is when the classifier doesn't return the expected
    // output or not in the expected format. We only skip the processing for
    // this item but we keep it in the queue to try at another time.
    // The AI output is not fully deterministic even with a temperature of 0.0
    // so later attempts may result in correct output.
    catch (UnexpectedValueException $exception) {
      // Retrieve how many classification attempts have already been made.
      $existing_record = $workflow->getClassificationProgress($entity);
      $attempts = $existing_record['attempts'] ?? 0;

      // Mark as failed if the entity has reached the classification attempts
      // limit.
      if ($attempts >= $workflow->getAttemptsLimit()) {
        $this->getLogger()->error('Classification failure for @bundle_label @entity_id: @error', [
          '@bundle_label' => $bundle_label,
          '@entity_id' => $entity->id(),
          '@error' => $exception->getMessage(),
        ]);

        $message = strtr('Classification failure: limit of @limit attempts reached.', [
          '@limit' => $workflow->getAttemptsLimit(),
        ]);
        $this->updateClassificationStatus($entity, $workflow, $message, 'failed');
      }
      // Otherwise update the progress record to increment the attempts number,
      // without creating a new revision for the entity since this is temporary.
      // Throw an exception to keep the item in the queue.
      else {
        $this->getLogger()->error('Temporary classification failure for @bundle_label @entity_id: @error', [
          '@bundle_label' => $bundle_label,
          '@entity_id' => $entity->id(),
          '@error' => $exception->getMessage(),
        ]);

        // Keep the status as queued but update the progress record attempts.
        $workflow->updateClassificationProgress($entity, 'AI classification failed; skipping temporarily.', 'queued');

        // Rethrow the exception to keep the item in the queue so it can be
        // processed again later on.
        throw $exception;
      }
    }
    // For other exceptions, like invalid configuration we stop the entire
    // queue processing but let the item in the queue for when the issue is
    // solved.
    catch (\Exception $exception) {
      $this->getLogger()->error('Error while classifying @bundle_label @entity_id: @error', [
        '@bundle_label' => $bundle_label,
        '@entity_id' => $entity->id(),
        '@error' => $exception->getMessage(),
      ]);
      throw new SuspendQueueException($exception->getMessage());
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
   * @param string $message
   *   A message (ex: error).
   * @param string $status
   *   The classification status (queued, processed or failed).
   */
  protected function updateClassificationStatus(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    string $message,
    string $status,
  ): void {
    // Update the classification progress record and create a revision if the
    // the classification status changed.
    if ($workflow->updateClassificationProgress($entity, $message, $status) !== $status) {
      $this->createEntityRevision($entity, $message);
    }
  }

  /**
   * Create a new revision for an entity with the given message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   * @param string $message
   *   Revision message.
   */
  protected function createEntityRevision(ContentEntityInterface $entity, string $message): void {
    if (!($entity instanceof RevisionInterface)) {
      return;
    }

    $entity->setRevisionCreationTime(time());
    $entity->setRevisionLogMessage($message);
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
      ->getStorage('ocha_content_classification_workflow')
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
