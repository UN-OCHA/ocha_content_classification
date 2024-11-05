<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\ocha_content_classification\Attribute\OchaContentClassifier;

/**
 * Plugin manager for the classifier plugins.
 */
class ClassifierPluginManager extends DefaultPluginManager implements ClassifierPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/OchaContentClassifier',
      $namespaces,
      $module_handler,
      ClassifierPluginInterface::class,
      OchaContentClassifier::class
    );

    $this->setCacheBackend($cache_backend, 'ocha_content_classification_classifier_plugins');
    $this->alterInfo('ocha_content_classification_classifier_info');
  }

}
