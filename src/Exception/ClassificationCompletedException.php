<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Exception;

/**
 * Classification already completed.
 */
class ClassificationCompletedException extends \Exception implements ExceptionInterface {}
