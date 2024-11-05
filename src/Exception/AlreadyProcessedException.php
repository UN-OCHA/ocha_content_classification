<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Exception;

/**
 * Entity already processed.
 */
class AlreadyProcessedException extends \Exception implements ExceptionInterface {}
