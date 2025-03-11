<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin;

/**
 * Base classifier plugin class.
 */
abstract class ClassifierPluginBase extends PluginBase implements ClassifierPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'classifier';
  }

}
