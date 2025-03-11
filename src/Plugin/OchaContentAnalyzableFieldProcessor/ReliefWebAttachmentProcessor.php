<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\OchaContentAnalyzableFieldProcessor;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Attribute\OchaContentAnalyzableFieldProcessor;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginBase;

/**
 * Process a ReliefWeb file attachment.
 */
#[OchaContentAnalyzableFieldProcessor(
  id: 'reliefweb_attachment',
  label: new TranslatableMarkup('ReliefWeb attachment'),
  description: new TranslatableMarkup('Process a ReliefWeb attachment.'),
  types: [
    'reliefweb_file',
  ]
)]
class ReliefWebAttachmentProcessor extends AnalyzableFieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function toString(string $placeholder, FieldItemListInterface $field): string {
    return $placeholder;
  }

  /**
   * {@inheritdoc}
   */
  public function toFiles(string $placeholder, FieldItemListInterface $field): array {
    $files = [];

    $index = 1;
    foreach ($field as $item) {
      $files[] = [
        'id' => $placeholder . $index,
        'mimetype' => $item->getFileMime(),
        'uri' => $item->loadFile()->getFileUri(),
      ];

      $index++;
    }

    return $files;
  }

}
