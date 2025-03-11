<?php

declare(strict_types=1);

namespace Drupal\ocha_content_classification\Plugin\OchaContentClassifier;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
use Drupal\ocha_content_classification\Helper\TextHelper;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface;
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
   * Maximum number of terms to allow term overrides to use in the prompt.
   */
  private const int TERM_LIMIT = 30;

  /**
   * Store the number of terms for a vocabulary.
   *
   * @var array<string,int>
   */
  protected array $vocabularyTermCount = [];

  /**
   * Store the loaded term properties.
   *
   * @var array
   */
  protected array $vocabularyPropertyValues = [];

  /**
   * Constructor.
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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface $analyzableFieldProcessorPluginManager
   *   The analyzable field processor plugin manager.
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
    protected Connection $database,
    protected ModuleHandlerInterface $moduleHandler,
    protected AnalyzableFieldProcessorPluginManagerInterface $analyzableFieldProcessorPluginManager,
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
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('plugin.manager.ocha_content_classification.analyzable_field_processor'),
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
        ['data' => $this->t('Placeholder'), 'style' => 'width: 25%', 'class' => ['required-mark']],
        ['data' => $this->t('Processor'), 'style' => 'width: 55%', 'class' => ['required-mark']],
        ['data' => $this->t('File'), 'style' => 'width: 5%'],
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
          '#options' => $this->getProcessorOptions($field_definition),
          '#default_value' => $config['analyzable']['fields'][$field_name]['processor'] ?? '',
          '#description' => NULL,
          '#required' => TRUE,
        ],
        'file' => [
          '#type' => 'checkbox',
          '#title' => $this->t('File'),
          '#title_display' => 'invisible',
          '#default_value' => !empty($config['analyzable']['fields'][$field_name]['file']),
          '#description' => NULL,
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
        ['data' => $this->t('Property'), 'style' => 'width: 15%'],
        ['data' => $this->t('Term overrides'), 'style' => 'width: 45%'],
      ],
    ];

    $classifiable_fields = $workflow->getEnabledClassifiableFields();
    $form['classifiable']['#access'] = !empty($classifiable_fields);

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
      $taxonomy_properties = $this->getTaxonomyProperties($vocabulary);
      $default_property = $config['classifiable']['fields'][$field_name]['property'] ?? 'custom';
      $default_property = isset($taxonomy_properties[$default_property]) ? $default_property : 'name';

      $form['classifiable']['fields'][$field_name]['property'] = [
        '#type' => 'select',
        '#title' => $this->t('Property'),
        '#title_display' => 'invisible',
        '#options' => $taxonomy_properties,
        '#default_value' => $default_property,
      ];

      if (isset($taxonomy_properties['custom'])) {
        $form['classifiable']['fields'][$field_name]['terms'] = [
          '#type' => 'details',
          '#title' => $this->t('Term overrides'),
          '#description' => $this->t('List of terms and their text override when listed in the prompt. Leave empty to exclude a term.'),
        ];

        $index = 1;
        $terms = $this->getTaxonomyTerms($vocabulary, self::TERM_LIMIT);
        foreach ($terms as $tid => $term) {
          $term_description = $config['classifiable']['fields'][$field_name]['terms'][$tid] ?? $term->getDescription();
          $form['classifiable']['fields'][$field_name]['terms'][$tid] = [
            '#type' => 'textarea',
            '#title' => $this->t('@index. @label', [
              '@index' => $index,
              '@label' => $term->label(),
            ]),
            '#default_value' => $term_description,
            '#cols' => 40,
            '#rows' => max(1, floor(mb_strlen($term_description) / 40)),
          ];
          $index++;
        }
      }
      else {
        $form['classifiable']['fields'][$field_name]['terms'] = [
          '#markup' => $this->t('N/A'),
        ];
      }

      $form['classifiable']['fields'][$field_name]['terms']['#states'] = [
        'visible' => [
          ':input[name="classifiable[fields][' . $field_name . '][property]"]' => ['value' => 'cusom'],
        ],
      ];
    }

    // Add content fields section.
    $form['fillable'] = [
      '#type' => 'details',
      '#title' => $this->t('Fillable Content'),
      '#open' => TRUE,
      '#description' => $this->t('List of fields that can be filled. Placeholders will be used to extract the data from the AI output.'),
    ];

    $form['fillable']['fields'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Field'), 'style' => 'width: 20%'],
        ['data' => $this->t('Property'), 'style' => 'width: 20%'],
        ['data' => $this->t('Placeholder'), 'style' => 'width: 60%', 'class' => ['required-mark']],
      ],
    ];

    $fillable_fields = $workflow->getEnabledFillableFields();
    $form['fillable']['#access'] = !empty($fillable_fields);

    foreach (array_keys($fillable_fields) as $field_name) {
      $properties = $workflow->getFillableFieldProperties($field_name);
      if (empty($properties)) {
        continue;
      }

      $field_definition = $field_definitions[$field_name];
      $field_properties = $field_definition->getFieldStorageDefinition()->getPropertyDefinitions();

      foreach ($properties as $property) {
        if (!isset($field_properties[$property])) {
          continue;
        }

        $field_property = $field_properties[$property];
        $field_name_extended = $field_name . '__' . $property;

        $form['fillable']['fields'][$field_name_extended] = [
          'field' => [
            '#plain_text' => $field_definition->getLabel(),
          ],
          'property' => [
            '#plain_text' => $field_property->getLabel(),
          ],
          'placeholder' => [
            '#type' => 'machine_name',
            '#title' => $this->t('Placeholder'),
            '#title_display' => 'invisible',
            '#default_value' => $config['fillable']['fields'][$field_name_extended]['placeholder'] ?? '',
            '#machine_name' => [
              'exists' => [$this, 'machineNameExists'],
            ],
            '#description' => NULL,
            '#required' => TRUE,
          ],
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
      '#max' => 4096,
    ];

    $system_prompt = $config['inference']['system_prompt'] ?? '';
    $form['inference']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $system_prompt,
      '#description' => $this->t('System prompt (ex: persona).'),
      '#cols' => 100,
      '#rows' => max(5, floor(mb_strlen($system_prompt) / 100)),
      '#required' => FALSE,
    ];

    $prompt = $config['inference']['prompt'] ?? '';
    $form['inference']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt'),
      '#default_value' => $prompt,
      '#description' => $this->t('Prompt to analyze and classify content. Use placeholders from analyzable and classifiable fields in the form <code>{placeholder}</code>. For analyzable fields, the placeholder will be replaced with the processed value. For classifiable fields, it will be replaced with a numbered list of terms (A1, A2, etc. for the first list in the prompt; B1, B2, etc. for the second). Structure the prompt to output XML, using the classifiable field <code>placeholders</code> as tags. Example: <code>&lt;theme&gt;Single item number (B1-B20)&lt;/theme&gt;</code>. You can also add a ":name" suffix to a placeholder to output the name instead of the value or list of terms.'),
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

    $prompt = $form_state->getValue(array_merge($parents, ['inference', 'prompt'])) ?: '';

    foreach (['analyzable', 'classifiable', 'fillable'] as $category) {
      $fields[$category] = $form_state->getValue(array_merge($parents, [$category, 'fields'])) ?: [];

      // If the `fields` is empty for example for the fillable fields, then
      // Drupal will assign an empty string as value when saving which doesn't
      // match the field type in the schema. So instead, if there are no fields
      // for a category, we ensure the value is an array.
      if (empty($fields[$category])) {
        $form_state->setValue(array_merge($parents, [$category, 'fields']), []);
      }
    }

    $error_messages = $this->generatePromptErrorMessages($prompt, $fields, $entity_type_id, $bundle);
    if (!empty($error_messages)) {
      $prompt_element_name = implode('][', array_merge($parents, ['inference', 'prompt']));
      foreach ($error_messages as $error_message) {
        $form_state->setErrorByName($prompt_element_name, $error_message);
      }
    }
  }

  /**
   * Generate error messages for prompt validation.
   *
   * @param string $prompt
   *   The prompt to validate.
   * @param array $fields
   *   Analyzable, classifiable and fillable fields configuration.
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
    array $fields,
    string $entity_type_id,
    string $bundle,
  ): array {
    $error_messages = [];

    if (empty($prompt)) {
      $error_messages[] = $this->t('The prompt must not be empty.');
      return $error_messages;
    }

    $prompt_errors = $this->validatePrompt($prompt, $fields);

    if (!empty($prompt_errors)) {
      $missing_placeholders = array_filter($prompt_errors, fn($item) => $item !== '');
      $extra_placeholders = array_filter($prompt_errors, fn($item) => $item === '');

      if (!empty($missing_placeholders)) {
        $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

        $missing_field_labels = array_map(function ($field_name) use ($field_definitions) {
          if (strpos($field_name, '__') !== FALSE) {
            [$field_name, $property] = explode('__', $field_name, 2);
            $field_definition = $field_definitions[$field_name];

            return $this->t('@field_label - @property_label', [
              '@field_label' => $field_definition->getlabel(),
              '@property_label' => $this->getFieldPropertyLabel($field_definition, $property),
            ]);
          }
          else {
            return $field_definitions[$field_name]->getLabel();
          }
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
  protected function getTaxonomyTerms(string $vocabulary, ?int $limit = NULL): array {
    $count = $this->getTaxonomyTermCount($vocabulary);
    if (isset($limit) && $count > $limit) {
      return [];
    }

    $entity_type = $this->entityTypeManager->getDefinition('taxonomy_term');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition($entity_type->getKey('bundle'), $vocabulary, '=');
    $query->condition($entity_type->getKey('published'), 1, '=');

    $ids = $query->execute();
    $terms = $storage->loadMultiple($ids);

    ksort($terms);
    return $terms;
  }

  /**
   * Get the number of published terms for a vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return int
   *   The number of terms in the vocabulary.
   */
  protected function getTaxonomyTermCount(string $vocabulary): int {
    if (!isset($this->vocabularyTermCount[$vocabulary])) {
      $entity_type = $this->entityTypeManager->getDefinition('taxonomy_term');
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');

      $query = $storage->getQuery();
      $query->accessCheck(FALSE);
      $query->condition($entity_type->getKey('bundle'), $vocabulary, '=');
      $query->condition($entity_type->getKey('published'), 1, '=');

      $this->vocabularyTermCount[$vocabulary] = $query->count()->execute() ?? 0;

    }
    return $this->vocabularyTermCount[$vocabulary];
  }

  /**
   * Get the fields that can be used for the term mapping in the prompt.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return array<string,string>
   *   Associative array of properties (fields) with the machine names as keys
   *   and the field labels as values.
   */
  protected function getTaxonomyProperties(string $vocabulary): array {
    $field_defintions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', $vocabulary);

    $allowed_types = ['string', 'integer'];
    $disallowed_fields = ['moderation_status', 'weight', 'parent'];

    $properties = [];

    if ($this->getTaxonomyTermCount($vocabulary) <= self::TERM_LIMIT) {
      $properties['custom'] = $this->t('Custom');
    }

    foreach ($field_defintions as $field_name => $field_definition) {
      if ($field_definition->isInternal() || $field_definition->isComputed() || $field_definition->isReadOnly()) {
        continue;
      }

      if (!in_array($field_definition->getType(), $allowed_types) || in_array($field_name, $disallowed_fields)) {
        continue;
      }

      $properties[$field_name] = $field_definition->getLabel();
    }

    return $properties;
  }

  /**
   * Get a mapping of term IDs to the given term property values.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param string $property
   *   The vocabulary field from which to retrieve unique values.
   *
   * @return array<int,mixed>
   *   Mapping of term IDs to the term property values.
   */
  protected function getTaxonomyTermPropertyValues(string $vocabulary, string $property): array {
    // Get the cached values.
    if (isset($this->vocabularyPropertyValues[$vocabulary][$property])) {
      return $this->vocabularyPropertyValues[$vocabulary][$property];
    }

    $entity_type_id = 'taxonomy_term';

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', $vocabulary);
    if (!isset($field_definitions[$property])) {
      return [];
    }

    $field_definition = $field_definitions[$property];
    $field_storage = $field_definition->getFieldStorageDefinition();

    if ($field_storage->isBaseField()) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $table = $entity_type->getBaseTable();
      $field = $property;
      $id_field = $entity_type->getKey('id');
      $bundle_field = $entity_type->getKey('bundle');
    }
    else {
      $table_mapping = $this->entityTypeManager->getStorage($entity_type_id)->getTableMapping();
      $table = $table_mapping->getFieldTableName($field_storage->getName());
      $field = $property . '_' . $field_storage->getMainPropertyName();
      $id_field = 'entity_id';
      $bundle_field = 'bundle';
    }

    $terms = $this->database
      ->select($table, $table)
      ->fields($table, [$id_field, $field])
      ->condition($table . '.' . $bundle_field, $vocabulary, '=')
      ->execute()
      ?->fetchAllKeyed() ?? [];

    // Cache the values since we may call that again when parsing the output
    // from the AI.
    $this->vocabularyPropertyValues[$vocabulary][$property] = $terms;

    return $terms;
  }

  /**
   * Get processor options.
   *
   * @return array
   *   An array of processor options.
   */
  protected function getProcessorOptions(FieldDefinitionInterface $field_definition): array {
    $field_type = $field_definition->getType();
    $definitions = $this->analyzableFieldProcessorPluginManager->getDefinitions();

    foreach ($definitions as $definition) {
      if (empty($definition['types']) || in_array($field_type, $definition['types'])) {
        $options[$definition['id']] = $definition['label'];
      }
    }
    return $options;
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
    // @todo we should check that the name is not used by any analyzable or
    // classifiable field, not just the fields from the given category.
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
    return $this->queryModel($entity, $workflow);
  }

  /**
   * Query the AI model.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return bool
   *   TRUE if the entity was updated.
   */
  protected function queryModel(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool {
    $system_prompt = $this->getPluginSetting('inference.system_prompt', '', FALSE);
    $prompt = $this->getPluginSetting('inference.prompt');

    $enabled_fields = [
      'analyzable' => $this->getEnabledFields('analyzable'),
      'classifiable' => $this->getEnabledFields('classifiable'),
      'fillable' => $this->getEnabledFields('fillable'),
    ];

    // Prepare the list of enabled analyzable and classifiable fields, keyed
    // by their placeholders.
    $fields = [];
    foreach ($enabled_fields as $type => $field_list) {
      foreach ($field_list as $field_name => $field) {
        $fields[$field['placeholder']] = $field + [
          'name' => $field_name,
          'type' => $type,
        ];
      }
    }

    // Store the prefixes of the list of terms in the prompt so we can extract
    // the values selected by the AI.
    $list_prefixes = [];

    // Prepare the text prompt to pass to the LLM.
    $prompt = $this->preparePrompt($prompt, $entity, $fields, $list_prefixes);

    // Prepare any extra files to pass to the LLM.
    $files = $this->prepareFiles($entity, $fields);

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
    $output = $plugin->query($prompt, $system_prompt, $parameters, TRUE, $files);
    if ($output === NULL) {
      throw new ClassifierPluginException('AI response error.');
    }
    elseif ($output === '') {
      throw new UnexpectedValueException('Empty AI output.');
    }

    // Parse the output of the AI.
    return $this->parseOutput($entity, $workflow, $output, $list_prefixes);
  }

  /**
   * Parse the AI output and update the entity to classify.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param string $output
   *   The AI output.
   * @param array $list_prefixes
   *   Prefixes for the lists. This is used to extract the selected values.
   *
   * @return bool
   *   TRUE if the entity was updated.
   */
  protected function parseOutput(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    string $output,
    array $list_prefixes,
  ): bool {
    // Parse the output and update the term fields.
    $updated_fields = [];

    // Get the list of fields that are marked for a forced update if they are
    // already populated.
    $force_update = [];
    foreach ($workflow->getEnabledFields(['classifiable', 'fillable']) as $field_name => $field_info) {
      $force_update[$field_name] = !empty($field_info['force']);
    }

    // Allow other module to alter the list of forced updates.
    $force_update_context = ['entity' => $entity];
    $this->moduleHandler->alter(
      'ocha_content_classification_force_field_update',
      $force_update,
      $workflow,
      $force_update_context,
    );

    // Process the classifiable fields.
    foreach ($this->getEnabledFields('classifiable') as $field_name => $settings) {
      // Skip if the field is not empty.
      if (empty($force_update[$field_name]) && !$entity->get($field_name)->isEmpty()) {
        continue;
      }

      $placeholder = $settings['placeholder'];
      $property = $settings['property'] ?? 'custom';
      $vocabulary = $this->getFieldVocabulary($entity->get($field_name)->getFieldDefinition());

      $terms = match($property) {
        'custom' => array_filter($settings['terms'] ?? []),
        default => $this->getTaxonomyTermPropertyValues($vocabulary, $property),
      };

      // Map the term IDs to the values the LLM is supposed to have selected.
      $mapping = [];
      if (!empty($terms)) {
        // If we used a prefixed list in the prompt, then we expect the AI to
        // have outputted the prefixed item numbers.
        if (isset($list_prefixes[$placeholder])) {
          $prefix = $list_prefixes[$placeholder];
          foreach (array_keys($terms) as $index => $id) {
            $mapping[$prefix . ($index + 1)] = $id;
          }
        }
        // Otherwise, we expect the AI to have outputted a term property like
        // the ISO3 code of countries.
        else {
          $mapping = array_flip($terms);
        }
      }

      // Extract the term IDs from the LLM output.
      $term_ids = $this->extractTermIds($output, $placeholder, $mapping);

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
      $updated_fields['classifiable'][$field_name] = $term_ids;
    }

    // Process the fillable fields.
    foreach ($this->getEnabledFields('fillable') as $field_name_extended => $settings) {
      [$field_name, $property] = explode('__', $field_name_extended, 2);

      // Skip if the field property is not empty.
      if (empty($force_update[$field_name]) && !empty($entity->get($field_name)->{$property})) {
        continue;
      }

      $placeholder = $settings['placeholder'];
      $field_definition = $entity->get($field_name)->getFieldDefinition();

      // Extract the text content for the fillable field.
      //
      // Note: we assume the content is plain text (or markdown) without
      // XML/HTML tags.
      //
      // @todo we may need something more robust to handle different formats.
      $content = $this->extractTaggedContent($output, $placeholder);
      if (!empty($content)) {
        // For simple string (type == text) we want to remove line breaks.
        $preserve_new_lines = $field_definition->getType() !== 'text';

        // The extracted values may be between tags like paragraph tags.
        if (mb_strpos($content, "</") !== FALSE) {
          $parts = $this->extractValuesBetweenTags($content);
          if (!empty($parts)) {
            $parts = array_map(fn($part) => TextHelper::sanitizeText($part, $preserve_new_lines), $parts);
            // We "glue" the parts using 2 line breaks which the standard way
            // to separate line breaks int markdown.
            $content = implode("\n\n", $parts);
          }
          else {
            $content = '';
          }
        }
        else {
          $content = TextHelper::sanitizeText($content, $preserve_new_lines);
        }
      }

      if (empty($content)) {
        throw new UnexpectedValueException(strtr('Missing content for @field_label - @property_label.', [
          '@field_label' => $field_definition->getLabel(),
          '@property_label' => $this->getFieldPropertyLabel($field_definition, $property),
        ]));
      }

      // Store the new field values.
      $updated_fields['fillable'][$field_name] = [$property => $content];
    }

    // Update the entity.
    foreach ($updated_fields as $type => $field_list) {
      foreach ($field_list as $field_name => $values) {
        // Classifiable fields are taxonomy term fields, we can simply pass
        // the new list of term IDs to update the field.
        if ($type === 'classifiable') {
          $entity->set($field_name, $values);
        }
        // For fillable fields, we only want to update the enabled properties.
        elseif ($type === 'fillable') {
          $field_item = $entity->get($field_name)->first() ??
            $entity->get($field_name)->appendItem()->applyDefaultValue(FALSE);
          // @todo find a better way than this workaround for the body field...
          if ($field_name === 'body' && empty($field_item->value) && !isset($values['value'])) {
            $field_item->set('value', '');
          }
          foreach ($values as $property => $value) {
            $field_item->set($property, $value);
          }
        }
      }
    }

    $updated = !empty($updated_fields);

    // Allow other modules to do something with the result.
    $updated = $updated || $this->moduleHandler->invokeAll('ocha_content_classification_post_classify_entity', [
      'entity' => $entity,
      'workflow' => $workflow,
      'classifier' => $this,
      'updated' => $updated,
      'data' => $output,
    ]);

    return $updated;
  }

  /**
   * Extract and processes tagged content, matching terms to their IDs.
   *
   * @param string $text
   *   The text containing tagged content.
   * @param string $tag
   *   The tag to extract content from.
   * @param array $mapping
   *   An associative array of possible AI value selection to term IDs.
   *
   * @return array
   *   An array of matching term IDs.
   */
  protected function extractTermIds(string $text, string $tag, array $mapping): array {
    if (empty($text) || empty($tag) || empty($mapping)) {
      return [];
    }

    $content = $this->extractTaggedContent($text, $tag);
    if (empty($content)) {
      return [];
    }

    // The extracted values may be between tags.
    if (mb_strpos($content, "</") !== FALSE) {
      $items = $this->extractValuesBetweenTags($content);
    }
    // Otherwise consider they are a comma separated list of values.
    else {
      $items = array_map('trim', explode(',', $content));
    }

    $extracted_term_ids = [];
    if (!empty($mapping)) {
      foreach ($items as $item) {
        $id = $mapping[$item] ?? $mapping[strtolower($item)] ?? NULL;
        if (isset($id)) {
          // Ensure uniqueness.
          $extracted_term_ids[$id] = $id;
        }
      }
    }

    return array_values($extracted_term_ids);
  }

  /**
   * Extract content between XML-like tags.
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
  public function extractTaggedContent(string $text, string $tag): string {
    $pattern = sprintf('<%1$s>(.*?)<\/%1$s>', preg_quote($tag, '/'));
    if (preg_match('/' . $pattern . '/s', $text, $matches) !== 1) {
      throw new UnexpectedValueException(strtr('Missing @tag from AI output', [
        '@tag' => $tag,
      ]));
    }
    return trim($matches[1]);
  }

  /**
   * Extract values between XML-like tags from a given input string.
   *
   * @param string $input
   *   The input string containing XML-like tags and values.
   *
   * @return array<int, string>
   *   Array of extracted values.
   */
  public function extractValuesBetweenTags(string $input): array {
    $pattern = '/<([^>]+)>(.*?)<\/\1>/s';
    $matches = [];
    preg_match_all($pattern, $input, $matches, \PREG_SET_ORDER);

    $values = [];
    foreach ($matches as $match) {
      $values[] = trim($match[2]);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity): bool {
    $bundle_label = EntityHelper::getBundleLabelFromEntity($entity);

    $fields['analyzable'] = $this->getEnabledFields('analyzable');
    if (empty($fields['analyzable'])) {
      throw new InvalidConfigurationException(strtr('No analyzable fields specified for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id() ?? 'new entity',
      ]));
    }

    $fields['classifiable'] = $this->getEnabledFields('classifiable');
    $fields['fillable'] = $this->getEnabledFields('fillable');
    if (empty($fields['classifiable']) && empty($fields['fillable'])) {
      throw new InvalidConfigurationException(strtr('No classifiable or fillable fields specified for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id() ?? 'new entity',
      ]));
    }

    $prompt = $this->getPluginSetting('inference.prompt');
    $prompt_errors = $this->validatePrompt($prompt, $fields);
    if (!empty($prompt_errors)) {
      throw new InvalidConfigurationException(strtr('Invalid classifier inference prompt for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id() ?? 'new entity',
      ]));
    }

    return TRUE;
  }

  /**
   * Validate the prompt against the analyzable and classifiable fields.
   *
   * This method checks if all placeholders in the prompt correspond to
   * existing field placeholders, and if all field placeholders are used
   * in the prompt.
   *
   * @param string $prompt
   *   The prompt string to validate.
   * @param array $fields
   *   An array of analyzable, classifiable and fillable fields.
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
  protected function validatePrompt(string $prompt, array $fields): array {
    if (empty($prompt) || empty($fields)) {
      return [];
    }

    $placeholders = [];
    $optional_placeholders = [];
    foreach ($fields as $field_type => $field_list) {
      foreach ($field_list as $field_name => $settings) {
        if (!empty($settings['placeholder'])) {
          $placeholders[$settings['placeholder']] = $field_name;
        }

        // Analyzable fields that are passed to the LLM as files, do not need to
        // appear in the prompt.
        if ($field_type === 'analyzable' && !empty($settings['file'])) {
          $optional_placeholders[$settings['placeholder']] = TRUE;
        }
      }
    }

    if (empty($placeholders)) {
      return [];
    }

    // Find placeholders in the prompt and validate against field placeholders.
    if (preg_match_all('/\{([a-z0-9_]+)(?::[^:}]+)?\}/', $prompt, $matches) > 0) {
      foreach (array_unique($matches[1]) as $prompt_placeholder) {
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

    // Remove optional placeholders that can be exempted from being in the
    // prompt.
    $placeholders = array_diff_key($placeholders, $optional_placeholders);

    // The remaining placeholders are those not used in the prompt or
    // placeholders not matching any field.
    return $placeholders;
  }

  /**
   * Prepare the prompt, replacing the placeholders.
   *
   * @param string $prompt
   *   Prompt template.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $fields
   *   List of analyzable and classifiable fields keyed by their placeholders.
   * @param array $list_prefixes
   *   Prefixes for the lists. This is used to extract the selected values.
   *
   * @return string
   *   Prompt ready for inference.
   *
   * @throws \Drupal\ocha_content_classification\Exception\MissingSettingException
   *   Exception if the prompt or field settings could not be retrieved.
   */
  protected function preparePrompt(string $prompt, ContentEntityInterface $entity, array $fields, array &$list_prefixes): string {
    $placeholder_pattern = implode('|', array_map('preg_quote', array_keys($fields)));

    $pattern = "/[{](?<placeholder>(?:{$placeholder_pattern}))(?<modifier>:[^:}]+)?[}]/";

    $prompt = preg_replace_callback($pattern, function ($matches) use ($entity, $fields, &$list_prefixes) {
      $placeholder = $matches['placeholder'];
      $modifier = $matches['modifier'] ?? '';

      $field = $fields[$placeholder];
      if ($field['type'] === 'analyzable') {
        return match($modifier) {
          '' => $this->getAnalyzableFieldValue($entity, $field),
          ':name' => $placeholder,
          default => '',
        };
      }
      elseif ($field['type'] === 'classifiable') {
        return match($modifier) {
          '' => $this->getClassifiableFieldValue($entity, $field, $list_prefixes),
          ':name' => $placeholder,
          default => '',
        };
      }
      elseif ($field['type'] === 'fillable') {
        return match($modifier) {
          '' => $placeholder,
          ':name' => $placeholder,
          default => '',
        };
      }
      else {
        return '';
      }
    }, $prompt);

    return $prompt;
  }

  /**
   * Get the files from analyzable fields to pass the AI.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $fields
   *   Analyzabled fields.
   *
   * @return array
   *   Files to pass to the AI
   */
  protected function prepareFiles(ContentEntityInterface $entity, array $fields): array {
    $files = [];
    foreach ($fields as $field) {
      if (empty($field['file']) || empty($field['name']) || empty($field['placeholder']) || empty($field['processor'])) {
        continue;
      }

      $field_name = $field['name'];
      $placeholder = $field['placeholder'];

      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }

      $field_files = $this->analyzableFieldProcessorPluginManager
        ->createInstance($field['processor'])
        ->toFiles($placeholder, $entity->get($field_name));

      $files = array_merge($files, $field_files);
    }
    return $files;
  }

  /**
   * Get the value of analyzable field to use in the prompt.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $field
   *   Settings of the analyzable field.
   *
   * @return string
   *   Field value to use in the prompt.
   */
  protected function getAnalyzableFieldValue(ContentEntityInterface $entity, array $field): string {
    if (empty($field['name']) || empty($field['placeholder']) || empty($field['processor'])) {
      return '';
    }

    $field_name = $field['name'];
    $placeholder = $field['placeholder'];

    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    if (!empty($field['file'])) {
      // @todo we should return the ID of the first document or a list of ids
      // instead of some hardcoded ID for the first document.
      return $placeholder . '1';
    }

    return $this->analyzableFieldProcessorPluginManager
      ->createInstance($field['processor'])
      ->toString($placeholder, $entity->get($field_name));
  }

  /**
   * Get the value of classifiable field to use in the prompt.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $field
   *   Settings of the analyzable field.
   * @param array $list_prefixes
   *   Prefixes for the lists of terms. This is used to extract the selected
   *   terms.
   *
   * @return string
   *   Field value to use in the prompt. This is a plain text list of terms.
   */
  protected function getClassifiableFieldValue(ContentEntityInterface $entity, array $field, array &$list_prefixes): string {
    $property = $field['property'] ?? 'custom';
    $placeholder = $field['placeholder'];

    if ($property === 'custom') {
      $terms = array_filter($field['terms'] ?? []);
    }
    else {
      $field_definition = $entity->get($field['name'])->getFieldDefinition();
      $vocabulary = $this->getFieldVocabulary($field_definition);
      $terms = $this->getTaxonomyTermPropertyValues($vocabulary, $property);
    }

    if (empty($terms)) {
      return '';
    }

    $prefix = $list_prefixes[$placeholder] ?? chr(ord('A') + count($list_prefixes));
    $list_prefixes[$placeholder] = $prefix;

    $list = [];
    foreach (array_values($terms) as $index => $value) {
      $list[] = $prefix . ($index + 1) . ') ' . $value;
    }

    return implode("\n", $list);
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

  /**
   * Get the label of a field property.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param stirng $property
   *   Field property.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   Field property label.
   */
  protected function getFieldPropertyLabel(FieldDefinitionInterface $field_definition, string $property): string|MarkupInterface {
    $properties = $field_definition->getFieldStorageDefinition()->getPropertyDefinitions();
    return $properties[$property] ? $properties[$property]->getLabel() : '';
  }

}
