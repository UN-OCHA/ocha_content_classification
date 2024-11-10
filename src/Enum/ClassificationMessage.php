<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Enum;

/**
 * Classification messages.
 */
enum ClassificationMessage: string {
  case QUEUED = 'Queued for automated classification.';
  case COMPLETED = 'Automated classification completed.';
  case FAILED = 'Automated classification failed.';
  case ATTEMPTS_LIMIT_REACHED = 'Automated classification stopped: too many attempts.';
  case FIELDS_ALREADY_SPECIFIED = 'Automated classification skipped: information already provided.';
  case FAILED_TEMPORARILY = 'Automated classification failed temporarily.';
}
