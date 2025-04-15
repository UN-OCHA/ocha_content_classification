<?php

namespace Drupal\ocha_content_classification\Helper;

/**
 * Helper to manipulate texts.
 */
class TextHelper {

  /**
   * Trim a text (extended version, removing Z and C unicode categories).
   *
   * @param string $text
   *   Text to trim.
   *
   * @return string
   *   Trimmed text.
   */
  public static function trimText($text) {
    return preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $text);
  }

  /**
   * Sanitize a UTF-8 string.
   *
   * This method performs the following operations:
   * 1. Replaces all whitespace characters with a single space.
   * 2. Replaces consecutive spaces with a single space.
   * 3. Removes all Unicode control characters.
   * 4. Removes heading and trailing spaces from the text.
   *
   * Optionally it also preserves new lines but collapses consecutive ones.
   *
   * @param string $text
   *   The input UTF-8 string to be processed.
   * @param bool $preserve_newline
   *   If TRUE, ensure the new lines are preserved.
   *
   * @return string
   *   Sanitized text.
   */
  public static function sanitizeText(string $text, bool $preserve_newline = FALSE): string {
    if ($preserve_newline) {
      // Remove new lines with a placeholder.
      $text = preg_replace('/(?:\r?\n\r?)+/', '{{{{NEWLINE}}}}', $text);
    }

    // Replace HTML non breaking spaces.
    $text = str_replace(['&nbsp;', '&#160;'], ' ', $text);

    // Replace all whitespace characters (including non-breaking spaces) with
    // a single space.
    $text = preg_replace('/\p{Z}+/u', ' ', $text);

    // Replace consecutive spaces with a single space.
    $text = preg_replace('/\s+/u', ' ', $text);

    // Remove all control and format characters.
    $text = preg_replace('/\p{C}/u', '', $text);

    if ($preserve_newline) {
      // Remove new lines with a placeholder.
      $text = str_replace('{{{{NEWLINE}}}}', "\n", $text);
    }

    return static::trimText($text);
  }

}
