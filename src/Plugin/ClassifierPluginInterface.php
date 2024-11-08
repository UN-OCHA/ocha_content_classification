<?php

namespace Drupal\ocha_content_classification\Plugin;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * Interface for the ocha_content_classification plugins.
 */
interface ClassifierPluginInterface {

  /**
   * Get the plugin label.
   *
   * @return string
   *   The plugin label.
   */
  public function getPluginLabel(): string;

  /**
   * Get the plugin type.
   *
   * @return string
   *   The plugin type.
   */
  public function getPluginType(): string;

  /**
   * Get the plugin logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger.
   */
  public function getLogger(): LoggerInterface;

  /**
   * Get a plugin setting.
   *
   * @param string $key
   *   The setting name. It can be nested in the form "a.b.c" to retrieve "c".
   * @param mixed $default
   *   Default value if the setting is missing.
   * @param bool $throw_if_null
   *   If TRUE and both the setting and default are NULL then an exception
   *   is thrown. Use this for example for mandatory settings.
   *
   * @return mixed
   *   The plugin setting for the key or the provided default.
   *
   * @throws \Drupal\ocha_content_classification\Exception\MissingSettingException
   *   Throws an exception if no setting could be found (= NULL).
   */
  public function getPluginSetting(string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed;

  /**
   * Classify an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return bool
   *   TRUE if the classification was successful.
   */
  public function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool;

  /**
   * Check if an entity can be processed by the classifier.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   *
   * @return bool
   *   TRUE if the entity can be processed.
   *
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If mandatory settings are missing or some other configuration is invalid.
   */
  public function validateEntity(ContentEntityInterface $entity): bool;

}
