<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

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
      'Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface',
      'Drupal\ocha_content_classification\Attribute\OchaContentClassifier'
    );

    $this->setCacheBackend($cache_backend, 'ocha_content_classification_classifier_plugins');
    $this->alterInfo('ocha_content_classification_classifier_info');
  }

}
