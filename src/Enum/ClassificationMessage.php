<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Enum;

/**
 * Classification messages.
 */
enum ClassificationMessage: string {
  case Queued = 'Queued for automated classification.';
  case Completed = 'Automated classification completed.';
  case Failed = 'Automated classification failed.';
  case AttemptsLimitReached = 'Automated classification stopped: too many attempts.';
  case FieldsAlreadySpecified = 'Automated classification skipped: information already provided.';
  case FailedTemporarily = 'Automated classification failed temporarily.';
}
