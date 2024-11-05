<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ocha_content_classification\Plugin\QueueWorker\ClassificationWorkflowQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a queue worker for each enabled classification workflow.
 *
 * @see \Drupal\ocha_content_classification\Plugin\QueueWorker\ClassificationWorkflowQueueWorker
 */
class ClassificationWorkflowQueueWorkerDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\EntityTypeManagerInterface $entityTypeManager
   *   The entity manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $derivatives = [];

    // Get the enabled workflows.
    $workflows = $this->entityTypeManager
      ->getStorage('ocha_content_classification_workflow')
      ->loadByProperties([
        'status' => 1,
      ]);

    foreach ($workflows as $workflow) {
      $derivatives[$workflow->id()] = [
        'title' => $this->t('Queue worker for classification workflow: @label', [
          '@label' => $workflow->label(),
        ]),
        'class' => ClassificationWorkflowQueueWorker::class,
        'cron' => [
          // Set how long cron should spend on this worker.
          // @todo get that from some config?
          'time' => 30,
        ],
      ] + $base_plugin_definition;
    }

    $this->derivatives = $derivatives;

    return $derivatives;
  }

}
