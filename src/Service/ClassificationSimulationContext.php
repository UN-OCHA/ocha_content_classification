<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Service;

/**
 * Tracks in-request classification simulation (dry-run) for safe hook handling.
 */
final class ClassificationSimulationContext {

  /**
   * Nesting depth for nested enter/exit pairs.
   */
  private int $depth = 0;

  /**
   * Mark the start of a simulated classification run.
   */
  public function enter(): void {
    $this->depth++;
  }

  /**
   * Mark the end of a simulated classification run.
   */
  public function exit(): void {
    $this->depth = max(0, $this->depth - 1);
  }

  /**
   * Whether a simulation is currently active.
   */
  public function isSimulating(): bool {
    return $this->depth > 0;
  }

}
