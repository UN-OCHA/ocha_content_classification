<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\OchaContentClassifier;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_content_classification\Attribute\OchaContentClassifier;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Exception\ClassifierPluginException;
use Drupal\ocha_content_classification\Exception\InvalidConfigurationException;
use Drupal\ocha_content_classification\Exception\UnexpectedValueException;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Classify a content entity using LLM inference.
 */
#[OchaContentClassifier(
  id: 'inference',
  label: new TranslatableMarkup('LLM inference'),
  description: new TranslatableMarkup('Classify an entity using LLM inference.')
)]
class InferenceClassifier extends ClassifierPluginBase {

  /**
   * Constructs a CompletionClassifierPluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface $completionPluginManager
   *   The completion plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected CompletionPluginManagerInterface $completionPluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('plugin.manager.ocha_ai.completion'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $workflow = $form_state->getFormObject()?->getEntity();
    if (!isset($workflow) || !($workflow instanceof ClassificationWorkflowInterface)) {
      $this->messenger->addError('Missing classification workflow.');
      return $form;
    }

    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      $workflow->getTargetEntityTypeId(),
      $workflow->getTargetBundle(),
    );
    if (empty($field_definitions)) {
      $this->messenger->addError('Unable to retrieve field definitions.');
      return $form;
    }

    $config = $this->getConfiguration();

    // Add content fields section.
    $form['analyzable'] = [
      '#type' => 'details',
      '#title' => $this->t('Analyzable Content'),
      '#open' => TRUE,
      '#description' => $this->t('List of fields that can be used as content to analyze for the classification. Placeholders can be used in the prompt and will be replaced with the processed field value.'),
    ];

    $form['analyzable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Field'), 'style' => 'width: 15%'],
        ['data' => $this->t('Placeholder'), 'style' => 'width: 15%', 'class' => ['required-mark']],
        ['data' => $this->t('Processor'), 'style' => 'width: 70%', 'class' => ['required-mark']],
      ],
    ];

    $analyzable_fields = $workflow->getEnabledAnalyzableFields();
    foreach (array_keys($analyzable_fields) as $field_name) {
      $field_definition = $field_definitions[$field_name];

      $form['analyzable']['fields'][$field_name] = [
        'label' => [
          '#plain_text' => $field_definition->getLabel(),
        ],
        'placeholder' => [
          '#type' => 'machine_name',
          '#title' => $this->t('Placeholder'),
          '#title_display' => 'invisible',
          '#default_value' => $config['analyzable']['fields'][$field_name]['placeholder'] ?? '',
          '#machine_name' => [
            'exists' => [$this, 'machineNameExists'],
          ],
          '#description' => NULL,
          '#required' => TRUE,
        ],
        'processor' => [
          '#type' => 'select',
          '#title' => $this->t('Processor'),
          '#title_display' => 'invisible',
          '#options' => $this->getProcessorOptions(),
          '#default_value' => $config['analyzable']['fields'][$field_name]['processor'] ?? '',
          '#description' => NULL,
          '#required' => TRUE,
        ],
      ];
    }

    // Add taxonomy term reference fields section.
    $form['classifiable'] = [
      '#type' => 'details',
      '#title' => $this->t('Classifiable Content'),
      '#open' => TRUE,
      '#description' => $this->t('List of fields that can be automatically classified. Placeholders can be used in the prompt and will be replaced with the list of terms. The number of terms to retrieve is determined by the min and max values.'),
    ];

    $form['classifiable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Field'), 'style' => 'width: 15%'],
        ['data' => $this->t('Placeholder'), 'style' => 'width: 15%', 'class' => ['required-mark']],
        ['data' => $this->t('Min'), 'style' => 'width: 5%'],
        ['data' => $this->t('Max'), 'style' => 'width: 5%'],
        ['data' => $this->t('Terms'), 'style' => 'width: 60%'],
      ],
    ];

    $classifiable_fields = $workflow->getEnabledClassifiableFields();
    foreach ($classifiable_fields as $field_name => $field_info) {
      $field_definition = $field_definitions[$field_name];

      $form['classifiable']['fields'][$field_name] = [
        'field' => [
          '#plain_text' => $field_definition->getLabel(),
        ],
        'placeholder' => [
          '#type' => 'machine_name',
          '#title' => $this->t('Prompt placeholder'),
          '#title_display' => 'invisible',
          '#default_value' => $config['classifiable']['fields'][$field_name]['placeholder'] ?? '',
          '#machine_name' => [
            'exists' => [$this, 'machineNameExists'],
          ],
          '#required' => TRUE,
          '#description' => NULL,
        ],
        'min' => [
          '#plain_text' => $field_info['min'] ?? 0,
        ],
        'max' => [
          '#plain_text' => $field_info['max'] ?? 1,
        ],
      ];

      // Add individual term fields if vocabulary has less than 30 terms.
      $vocabulary = $this->getFieldVocabulary($field_definition);
      $terms = $this->getTaxonomyTerms($vocabulary, 30);
      if (!empty($terms)) {
        $form['classifiable']['fields'][$field_name]['terms'] = [
          '#type' => 'details',
          '#title' => $this->t('Term overrides'),
          '#description' => $this->t('List of terms and their text override when listed in the prompt.'),
        ];

        $index = 1;
        foreach ($terms as $tid => $term) {
          $term_description = $config['classifiable']['fields'][$field_name]['terms'][$tid] ?? $term->getDescription();
          $form['classifiable']['fields'][$field_name]['terms'][$tid] = [
            '#type' => 'textarea',
            '#title' => $this->t('@index. @label', [
              '@index' => $index,
              '@label' => $term->label(),
            ]),
            '#default_value' => $term_description,
            '#cols' => 60,
            '#rows' => max(1, floor(mb_strlen($term_description) / 60)),
          ];
          $index++;
        }
      }
      else {
        $form['classifiable']['fields'][$field_name]['terms'] = [
          '#markup' => $this->t('No overrides, using the term names directly in the prompt.'),
        ];
      }
    }

    // Retrieve the list of completion plugins.
    $completion_plugin_options = [];
    foreach ($this->completionPluginManager->getAvailablePlugins() as $plugin) {
      $completion_plugin_options[$plugin->getPluginId()] = $plugin->getPluginLabel();
    }

    $form['inference'] = [
      '#type' => 'details',
      '#title' => $this->t('Inference settings'),
      '#open' => TRUE,
    ];

    $form['inference']['plugin_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Inference plugin'),
      '#options' => $completion_plugin_options,
      '#default_value' => $config['inference']['plugin_id'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['inference']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#description' => $this->t('Temperature parameter for the AI model, lower is more focused and deterministic.'),
      '#default_value' => $config['inference']['temperature'] ?? 0.0,
      '#required' => TRUE,
      '#step' => '.01',
      '#min' => 0.0,
      '#max' => 1.0,
    ];

    $form['inference']['top_p'] = [
      '#type' => 'number',
      '#title' => $this->t('Nucleus sampling (top_p)'),
      '#description' => $this->t('Top-p parameter for the AI model, lower values make responses more focused, higher values more diverse.'),
      '#default_value' => $config['inference']['top_p'] ?? 0.2,
      '#required' => TRUE,
      '#step' => '.01',
      '#min' => 0.0,
      '#max' => 1.0,
    ];

    $form['inference']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#description' => $this->t('Max tokens parameter for the AI model to limit the length of its response. Higher values allow longer outputs.'),
      '#default_value' => $config['inference']['max_tokens'] ?? 512,
      '#required' => TRUE,
      '#step' => '1',
      '#min' => 128,
      '#max' => 1024,
    ];

    $prompt = $config['inference']['prompt'] ?? '';
    $form['inference']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt'),
      '#default_value' => $prompt,
      '#description' => $this->t('Prompt to analyze and classify content. Use placeholders from analyzable and classifiable fields in the form <code>{placeholder}</code>. For analyzable fields, the placeholder will be replaced with the processed value. For classifiable fields, it will be replaced with a numbered list of terms (A1, A2, etc. for the first list in the prompt; B1, B2, etc. for the second). Structure the prompt to output XML, using the classifiable field <code>placeholders</code> as tags. Example: <code>&lt;theme&gt;Single item number (B1-B20)&lt;/theme&gt;</code>.'),
      '#cols' => 100,
      '#rows' => max(15, floor(mb_strlen($prompt) / 100)),
      '#required' => TRUE,
    ];

    // Show the settings if there is no selected inference plugin.
    $form['inference']['#open'] = empty($config['inference.plugin_id']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $workflow = $form_state->getFormObject()?->getEntity();
    if (!isset($workflow) || !($workflow instanceof ClassificationWorkflowInterface)) {
      $form_state->setErrorByName('', 'Missing classification workflow.');
      return;
    }

    $parents = $form['#parents'];
    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();

    $prompt = $form_state->getValue(array_merge($parents, ['inference', 'prompt']), '');
    $analyzable_fields = $form_state->getValue(array_merge($parents, ['analyzable', 'fields']), []);
    $classifiable_fields = $form_state->getValue(array_merge($parents, ['classifiable', 'fields']), []);

    $error_messages = $this->generatePromptErrorMessages($prompt, $analyzable_fields, $classifiable_fields, $entity_type_id, $bundle);
    if (!empty($error_messages)) {
      $prompt_element_name = implode('][', array_merge($parents, ['inference', 'prompt']));
      foreach ($error_messages as $error_message) {
        $form_state->setErrorByName($prompt_element_name, $error_message);
      }
    }
  }

  /**
   * Generates error messages for prompt validation.
   *
   * @param string $prompt
   *   The prompt to validate.
   * @param array $analyzable_fields
   *   Analyzable fields configuration.
   * @param array $classifiable_fields
   *   Classifiable fields configuration.
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Entity bundle.
   *
   * @return array
   *   An array of error messages, if any.
   */
  private function generatePromptErrorMessages(
    string $prompt,
    array $analyzable_fields,
    array $classifiable_fields,
    string $entity_type_id,
    string $bundle,
  ): array {
    $error_messages = [];

    if (empty($prompt)) {
      $error_messages[] = $this->t('The prompt must not be empty.');
      return $error_messages;
    }

    $prompt_errors = $this->validatePrompt($prompt, $analyzable_fields, $classifiable_fields);

    if (!empty($prompt_errors)) {
      $missing_placeholders = array_filter($prompt_errors, fn($item) => $item !== '');
      $extra_placeholders = array_filter($prompt_errors, fn($item) => $item === '');

      if (!empty($missing_placeholders)) {
        $fields = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

        $missing_field_labels = array_map(function ($field_name) use ($fields) {
          return $fields[$field_name]->getLabel();
        }, $missing_placeholders);

        $error_messages[] = $this->formatPlural(
          count($missing_field_labels),
          'Missing placeholder for field: @fields.',
          'Missing placeholders for fields: @fields.',
          ['@fields' => implode(', ', $missing_field_labels)]
        );
      }

      if (!empty($extra_placeholders)) {
        $error_messages[] = $this->formatPlural(
          count($extra_placeholders),
          'Extra placeholder not matching any field: @placeholders.',
          'Extra placeholders not matching any field: @placeholders.',
          ['@placeholders' => implode(', ', array_keys($extra_placeholders))]
        );
      }
    }

    return $error_messages;
  }

  /**
   * Get the taxonomy terms for a given vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param ?int $limit
   *   If the total number of terms in the vocabulary exceeds this limit,
   *   an empty array is returned instead of loading the terms. If NULL,
   *   no limit is applied and all terms are loaded.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   An array of taxonomy terms.
   */
  protected function getTaxonomyTerms(string $vocabulary, ?int $limit = NULL) {
    $entity_type = $this->entityTypeManager->getDefinition('taxonomy_term');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition($entity_type->getKey('bundle'), $vocabulary, '=');
    $query->condition($entity_type->getKey('published'), 1, '=');

    $count = (clone $query)->count()->execute();
    if (isset($limit) && $count > $limit) {
      return [];
    }

    $ids = $query->execute();
    $terms = $storage->loadMultiple($ids);

    ksort($terms);
    return $terms;
  }

  /**
   * Get processor options.
   *
   * @return array
   *   An array of processor options.
   */
  protected function getProcessorOptions() {
    // @todo create a plugin system to handle some transformations like
    // converting markdown to HTML etc.
    return [
      'trimmed' => $this->t('Trimmed'),
      'raw' => $this->t('Raw'),
    ];
  }

  /**
   * Check if a machine name already exists.
   *
   * @param string $machine_name
   *   The machine name to check.
   * @param array $form
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return bool
   *   TRUE if the machine name exists, FALSE otherwise.
   */
  public function machineNameExists(string $machine_name, array $form, FormStateInterface $form_state): bool {
    if ($machine_name === '') {
      return FALSE;
    }

    $parents = $form['#parents'];
    $machine_name_property = array_pop($parents);
    $form_field_name = array_pop($parents);

    // Check if the value is used by another field.
    $values = $form_state->getValue($parents);
    if (!empty($values)) {
      foreach ($values as $field_name => $field_values) {
        if ($field_values[$machine_name_property] === $machine_name && $form_field_name !== $field_name) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool {
    $prompt = $this->getPluginSetting('inference.prompt');

    $analyzable_replacements = $this->getAnalyzableFieldReplacements($entity);
    $classifiable_replacements = $this->getClassifiableFieldReplacements($entity);
    $replacements = $analyzable_replacements + $classifiable_replacements;

    $list_prefixes = [];

    $prompt = $this->preparePrompt($prompt, $replacements, $list_prefixes);

    // Retrieve the model parameters.
    $parameters = [
      'temperature' => (float) $this->getPluginSetting('inference.temperature'),
      'top_p' => (float) $this->getPluginSetting('inference.top_p'),
      'max_tokens' => (int) $this->getPluginSetting('inference.max_tokens'),
    ];

    // Retrieve the AI plugin.
    $plugin_id = $this->getPluginSetting('inference.plugin_id');
    $plugin = $this->completionPluginManager->getPlugin($plugin_id);

    // Query the LLM to classify the job.
    $output = $plugin->query($prompt, '', $parameters, TRUE);
    if ($output === NULL) {
      throw new ClassifierPluginException('AI response error.');
    }
    elseif ($output === '') {
      throw new UnexpectedValueException('Empty AI output.');
    }

    // Parse the output and update the term fields.
    $updated_fields = [];
    foreach ($this->getEnabledFields('classifiable') as $field_name => $settings) {
      if (empty($settings['placeholder'])) {
        continue;
      }

      $placeholder = '{' . $settings['placeholder'] . '}';
      if (!isset($list_prefixes[$placeholder])) {
        continue;
      }

      $prefix = $list_prefixes[$placeholder];
      $terms = $classifiable_replacements[$placeholder];

      $mapping = [];
      foreach (array_keys($terms) as $index => $id) {
        $mapping[$prefix . ($index + 1)] = $id;
      }

      $term_ids = $this->extractTermIds($output, $settings['placeholder'], $mapping);

      // Check that we have the expected number of terms for the field.
      $term_id_count = count($term_ids);
      $min = $workflow->getClassifiableFieldMin($field_name);
      $max = $workflow->getClassifiableFieldMax($field_name);
      $is_under_min = $term_id_count < $min;
      $is_over_max = $max !== -1 && $term_id_count > $max;

      if ($is_under_min || $is_over_max) {
        $range = $max === -1 ? "at least $min" : "$min-$max";
        throw new UnexpectedValueException(strtr('Number of terms for @field is outside the allowed range (@range).', [
          '@field' => $entity->get($field_name)->getFieldDefinition()->getLabel(),
          '@range' => $range,
        ]));
      }

      // Store the new field values.
      $updated_fields[$field_name] = $term_ids;
    }

    // Update the entity.
    foreach ($updated_fields as $field_name => $term_ids) {
      $entity->set($field_name, $term_ids);
    }

    return TRUE;
  }

  /**
   * Extracts and processes tagged content, matching terms to their IDs.
   *
   * @param string $text
   *   The text containing tagged content.
   * @param string $tag
   *   The tag to extract content from.
   * @param array $terms
   *   An associative array of term list numbers to term IDs.
   *
   * @return array
   *   An array of matching term IDs.
   */
  protected function extractTermIds(string $text, string $tag, array $terms): array {
    if (empty($text) || empty($tag) || empty($terms)) {
      return [];
    }

    $content = $this->extractTaggedContent($text, $tag);
    if (empty($content)) {
      return [];
    }

    $items = array_map('trim', explode(',', $content));

    $extracted_term_ids = [];
    foreach ($items as $item) {
      if (isset($terms[$item])) {
        $id = $terms[$item];
        $extracted_term_ids[$id] = $id;
      }
    }

    return array_values($extracted_term_ids);
  }

  /**
   * Extracts content between XML-like tags.
   *
   * @param string $text
   *   The text to search in.
   * @param string $tag
   *   The tag to look for.
   *
   * @return string
   *   The content between the specified tags.
   *
   * @throws \Drupal\ocha_content_classification\Exception\UnexpectedValueException
   *   An exception if the tag is not in the text.
   */
  protected function extractTaggedContent(string $text, string $tag): string {
    $pattern = sprintf('<%1$s>(.*?)<\/%1$s>', preg_quote($tag, '/'));
    if (preg_match('/' . $pattern . '/s', $text, $matches) !== 1) {
      throw new UnexpectedValueException(strtr('Missing @tag from AI output', [
        '@tag' => $tag,
      ]));
    }
    return trim($matches[1]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity): bool {
    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    $analyzable_fields = $this->getEnabledFields('analyzable');
    if (empty($analyzable_fields)) {
      throw new InvalidConfigurationException(strtr('No analyzable fields specified for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    $classifiable_fields = $this->getEnabledFields('classifiable');
    if (empty($classifiable_fields)) {
      throw new InvalidConfigurationException(strtr('No classifiable fields specified for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    $prompt = $this->getPluginSetting('inference.prompt');
    $prompt_errors = $this->validatePrompt($prompt, $analyzable_fields, $classifiable_fields);
    if (!empty($prompt_errors)) {
      throw new InvalidConfigurationException(strtr('Invalid classifier inference prompt for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id(),
      ]));
    }

    return TRUE;
  }

  /**
   * Validates the prompt against the analyzable and classifiable fields.
   *
   * This method checks if all placeholders in the prompt correspond to
   * existing field placeholders, and if all field placeholders are used
   * in the prompt.
   *
   * @param string $prompt
   *   The prompt string to validate.
   * @param array<string, array<string, mixed>> $analyzable_fields
   *   An array of analyzable fields, where keys are field names and values
   *   are arrays containing at least a 'placeholder' key.
   * @param array<string, array<string, mixed>> $classifiable_fields
   *   An array of classifiable fields, where keys are field names and values
   *   are arrays containing at least a 'placeholder' key.
   *
   * @return array
   *   An empty array if the prompt is valid. Otherwise, an associative array
   *   where:
   *   - Keys are placeholders.
   *   - Values are either:
   *     - The corresponding field name for fields not used in the prompt.
   *     - An empty string for placeholders in the prompt not matching existing
   *       fields.
   */
  protected function validatePrompt(string $prompt, array $analyzable_fields, array $classifiable_fields): array {
    if (empty($prompt) || empty($analyzable_fields) || empty($classifiable_fields)) {
      return [];
    }

    $fields = $analyzable_fields + $classifiable_fields;

    $placeholders = [];
    foreach ($fields as $field_name => $settings) {
      if (!empty($settings['placeholder'])) {
        $placeholders[$settings['placeholder']] = $field_name;
      }
    }

    if (empty($placeholders)) {
      return [];
    }

    // Find placeholders in the prompt and validate against field placeholders.
    if (preg_match_all('/\{([a-z0-9_]+)\}/', $prompt, $matches) > 0) {
      foreach ($matches[1] as $prompt_placeholder) {
        if (!isset($placeholders[$prompt_placeholder])) {
          // Placeholder in prompt doesn't match any field placeholder.
          $placeholders[$prompt_placeholder] = '';
        }
        else {
          // Placeholder is valid, remove it from the list.
          unset($placeholders[$prompt_placeholder]);
        }
      }
    }

    // The remaining placeholders are those not used in the prompt or
    // placeholders not matching any field.
    return $placeholders;
  }

  /**
   * Prepare the prompt, replacing the placeholders.
   *
   * @param string $prompt
   *   Prompt template.
   * @param array<string,string|array> $replacements
   *   Replacements for the field placeholders in the prompt. For analyzable
   *   fields, the replacements are strings. For classifiable fields, the
   *   replacements are list of terms.
   * @param array $list_prefixes
   *   Prefixes for the lists. This is used to extract the selected values.
   *
   * @return string
   *   Prompt ready for inference.
   *
   * @throws \Drupal\ocha_content_classification\Exception\MissingSettingException
   *   Exception if the prompt or field settings could not be retrieved.
   */
  protected function preparePrompt(string $prompt, array $replacements, array &$list_prefixes): string {
    $pattern = '#' . implode('|', array_map('preg_quote', array_keys($replacements))) . '#';

    $prompt = preg_replace_callback($pattern, function ($matches) use ($replacements, &$list_prefixes) {
      $placeholder = $matches[0];
      $replacement = $replacements[$placeholder];

      // Classifiable fields - list of terms.
      if (is_array($replacement) && !empty($replacement)) {
        $prefix = $list_prefixes[$placeholder] ?? chr(ord('A') + count($list_prefixes));
        $list_prefixes[$placeholder] = $prefix;
        $list = array_map(fn($value) => $prefix . $value, $replacement);
        return implode("\n", $list) . "\n";
      }
      // Analyzable fields - strings.
      else {
        return $replacement;
      }
    }, $prompt);

    return $prompt;
  }

  /**
   * Get the placeholders and their replacements for the analyzable fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   *
   * @return array
   *   Associative array with field placeholders as keys and their processed
   *   values as values.
   */
  protected function getAnalyzableFieldReplacements(ContentEntityInterface $entity): array {
    $analyzable_fields = $this->getPluginSetting('analyzable.fields');
    $replacements = [];

    // Generate the list of replacements for the fields to analyze.
    foreach ($analyzable_fields as $field_name => $settings) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      if (empty($settings['placeholder'])) {
        continue;
      }

      // @todo review if/when we introduce processor plugins that can handle
      // more complex data like taxonomy terms.
      $value = $entity->get($field_name)->getString();
      $value = strip_tags($value);
      $value = match ($settings['processor']) {
        'trimmed' => trim($value),
        default => $value,
      };

      $replacements['{' . $settings['placeholder'] . '}'] = $value;
    }

    return $replacements;
  }

  /**
   * Get the placeholders and their replacements for the classifiable fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   *
   * @return array
   *   Associative array with field placeholders as keys and the list of terms
   *   as values.
   */
  protected function getClassifiableFieldReplacements(ContentEntityInterface $entity): array {
    $classifiable_fields = $this->getPluginSetting('classifiable.fields');
    $replacements = [];

    // Generate the list of replacements for the fields to classify.
    foreach ($classifiable_fields as $field_name => $settings) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      if (empty($settings['placeholder'])) {
        continue;
      }

      $placeholder = $settings['placeholder'];

      $terms = [];
      if (!empty($settings['terms'])) {
        $terms = $settings['terms'];
      }
      else {
        $field_definition = $entity->get($field_name)->getFieldDefinition();
        $vocabulary = $this->getFieldVocabulary($field_definition);
        if (!empty($vocabulary)) {
          foreach ($this->getTaxonomyTerms($vocabulary) ?? [] as $term) {
            $terms[$term->id()] = $term->getName();
          }
        }
      }

      $list = [];
      if (!empty($terms)) {
        ksort($terms);

        $index = 1;
        foreach ($terms as $id => $term) {
          $list[$id] = $index . ') ' . $term;
          $index++;
        }
      }

      $replacements['{' . $placeholder . '}'] = $list;
    }

    return $replacements;
  }

  /**
   * Get the enabled (with a placeholders) analyzable or classifiable fields.
   *
   * @param string $type
   *   Either 'analyzable' or 'classifiable'.
   *
   * @return array<string,mixed>
   *   Associative array of fields and their settings keyed by field names.
   *
   * @throws \Drupal\ocha_content_classification\Exception\MissingSettingException
   *   Exception if the fields settings could not be retrieved.
   */
  protected function getEnabledFields(string $type): array {
    $fields = $this->getPluginSetting($type . '.fields');
    return array_filter($fields, fn($settings) => !empty($settings['placeholder']));
  }

  /**
   * Get the target vocabulary for taxonomy reference field.
   *
   * Note: this only works well for fields referencing a single vocabulary.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   *
   * @return string
   *   Vocabulary or empty string if the field is not a taxonomy reference field
   *   or no vocabulary was found.
   */
  protected function getFieldVocabulary(FieldDefinitionInterface $field_definition): string {
    if ($field_definition->getType() === 'entity_reference' && $field_definition->getSetting('target_type') === 'taxonomy_term') {
      $bundles = $field_definition->getSetting('handler_settings')['target_bundles'] ?? [];
      return reset($bundles);
    }
    return '';
  }

}
