<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Enum;

/**
 * Classification statuses.
 */
enum ClassificationStatus: string {
  case QUEUED = 'queued';
  case COMPLETED = 'completed';
  case FAILED = 'failed';
}
