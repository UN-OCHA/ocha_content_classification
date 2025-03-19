<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Enum;

/**
 * Classification statuses.
 */
enum ClassificationStatus: string {
  case Queued = 'queued';
  case Completed = 'completed';
  case Failed = 'failed';
}
