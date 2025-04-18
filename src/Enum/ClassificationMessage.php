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

  /**
   * Removes all classification messages from a string.
   *
   * @param string $input
   *   The string from which to remove occurrences of classification messages.
   *
   * @return string
   *   The cleaned string with normalized whitespaces.
   */
  public static function removeAllClassificationMessages(string $input): string {
    // Remove all classification messages.
    foreach (self::cases() as $case) {
      $input = str_replace($case->value, '', $input);
    }

    // Remove consecutive whitespaces.
    $input = preg_replace('/\s+/', ' ', $input);

    // Trim leading and trailing whitespaces.
    return trim($input);
  }

  /**
   * Remove previous classification messages from a string and append new one.
   *
   * @param string $input
   *   The string to process.
   * @param \Drupal\ocha_content_classification\EnumClassificationMessage $message
   *   The classification message to add.
   *
   * @return string
   *   The resulting string with the message appended.
   */
  public static function addClassificationMessage(string $input, self $message): string {
    // First clean the input string.
    $cleaned = self::removeAllClassificationMessages($input);

    // If the cleaned string is empty, just return the message.
    if (empty($cleaned)) {
      return $message->value;
    }

    // Otherwise, add the message with a space separator.
    return $cleaned . ' ' . $message->value;
  }

}
