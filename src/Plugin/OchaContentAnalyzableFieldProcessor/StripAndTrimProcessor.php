<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\OchaContentAnalyzableFieldProcessor;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Attribute\OchaContentAnalyzableFieldProcessor;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginBase;

/**
 * Strip tags from and trim an analyzable field value.
 */
#[OchaContentAnalyzableFieldProcessor(
  id: 'strip_and_trim',
  label: new TranslatableMarkup('Strip tags and trim'),
  description: new TranslatableMarkup('Process a field value by stripping tags from and trimming its string representation.'),
  types: [
    'text',
    'text_long',
    'text_with_summary',
    'string',
    'string_long',
  ]
)]
class StripAndTrimProcessor extends AnalyzableFieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function toString(string $placeholder, FieldItemListInterface $field): string {
    $value = $field->getString();
    return trim(strip_tags($value));
  }

  /**
   * {@inheritdoc}
   */
  public function toFiles(string $placeholder, FieldItemListInterface $field): array {
    $files = [];

    $index = 1;
    foreach ($field as $item) {
      $data = $item->getString();
      if (empty($data)) {
        continue;
      }

      $mimetype = 'text/plain';
      if (isset($item->format)) {
        if (stripos($item->format, 'markdown') !== FALSE) {
          $mimetype = 'text/markdown';
        }
        elseif (stripos($item->format, 'html') !== FALSE) {
          $mimetype = 'text/html';
        }
      }

      $files[] = [
        'id' => $placeholder . $index,
        'mimetype' => $mimetype,
        'data' => $data,
      ];

      $index++;
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function filterFiles(FieldItemListInterface $field, array $supported_file_types): void {
    $field->filter(function ($item) use ($supported_file_types) {
      $data = $item->getString();
      if (empty($data)) {
        return FALSE;
      }

      $mimetype = 'text/plain';
      if (isset($item->format)) {
        if (stripos($item->format, 'markdown') !== FALSE) {
          $mimetype = 'text/markdown';
        }
        elseif (stripos($item->format, 'html') !== FALSE) {
          $mimetype = 'text/html';
        }
      }

      return isset($supported_file_types[$mimetype]) && strlen($data) < $supported_file_types[$mimetype];
    });
  }

}
