<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Exception;

/**
 * Classification is marked as failed.
 */
class ClassificationFailedException extends \Exception implements ExceptionInterface {}
