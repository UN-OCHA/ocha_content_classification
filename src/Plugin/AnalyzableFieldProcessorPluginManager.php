<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\ocha_content_classification\Attribute\OchaContentAnalyzableFieldProcessor;

/**
 * Plugin manager for the analyzable field processor plugins.
 */
class AnalyzableFieldProcessorPluginManager extends DefaultPluginManager implements AnalyzableFieldProcessorPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/OchaContentAnalyzableFieldProcessor',
      $namespaces,
      $module_handler,
      AnalyzableFieldProcessorPluginInterface::class,
      OchaContentAnalyzableFieldProcessor::class
    );

    $this->setCacheBackend($cache_backend, 'ocha_content_classification_analyzable_field_processor_plugins');
    $this->alterInfo('ocha_content_classification_analyzable_field_processor_info');
  }

}
