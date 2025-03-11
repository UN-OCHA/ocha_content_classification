<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a analyzable field processor plugin attribute object.
 *
 * Plugin Namespace: Plugin\OchaContentAnalyzableFieldProcessor.
 *
 * @see \Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginBase
 * @see \Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginInterface
 * @see \Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaContentAnalyzableFieldProcessor extends Plugin {

  /**
   * Constructor.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the plugin.
   * @param array $types
   *   An array of field types the field processor supports. If empty, then it
   *   applies to all the field types.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly array $types,
  ) {}

}
