<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a classifier plugin attribute object.
 *
 * Plugin Namespace: Plugin\OchaContentClassifier.
 *
 * @see \Drupal\ocha_content_classification\Plugin\ClassifierPluginBase
 * @see \Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface
 * @see \Drupal\ocha_content_classification\Plugin\ClassifierPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaContentClassifier extends Plugin {

  /**
   * Constructs a classifier attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
  ) {}

}
