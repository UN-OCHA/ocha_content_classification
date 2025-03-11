<?php

namespace Drupal\ocha_content_classification\Plugin;

use Psr\Log\LoggerInterface;

/**
 * Interface for the ocha_content_classification plugins.
 */
interface PluginInterface {

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

}
