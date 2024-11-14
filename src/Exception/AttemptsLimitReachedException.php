<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Exception;

/**
 * Classification attempts limit reached.
 */
class AttemptsLimitReachedException extends ClassificationFailedException {}
