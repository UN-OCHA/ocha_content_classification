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
use Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability;
use Drupal\ocha_content_classification\Attribute\OchaContentClassifier;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Exception\ClassificationFailedException;
use Drupal\ocha_content_classification\Exception\ClassifierPluginException;
use Drupal\ocha_content_classification\Exception\InvalidConfigurationException;
use Drupal\ocha_content_classification\Exception\UnexpectedValueException;
use Drupal\ocha_content_classification\Helper\EntityHelper;
use Drupal\ocha_content_classification\Helper\TextHelper;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginBase;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Classify a content entity using structured LLM inference.
 */
#[OchaContentClassifier(
  id: 'structured_inference',
  label: new TranslatableMarkup('LLM structured inference'),
  description: new TranslatableMarkup('Classify an entity using structured LLM inference.')
)]
class StructuredInferenceClassifier extends ClassifierPluginBase {

  /**
   * Maximum number of terms to allow term overrides to use in the prompt.
   */
  private const int TERM_LIMIT = 30;

  /**
   * Vocabulary "Value" option: per-term manual const/title/description.
   */
  private const string VALUE_CUSTOM = 'custom';

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
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface $completionPluginManager
   *   The completion plugin manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
    protected ModuleHandlerInterface $moduleHandler,
    protected AnalyzableFieldProcessorPluginManagerInterface $analyzableFieldProcessorPluginManager,
    protected CompletionPluginManagerInterface $completionPluginManager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $configFactory,
      $loggerFactory,
      $entityTypeManager,
      $entityFieldManager,
      $database,
      $moduleHandler,
      $analyzableFieldProcessorPluginManager,
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
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('plugin.manager.ocha_content_classification.analyzable_field_processor'),
      $container->get('plugin.manager.ocha_ai.completion'),
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

    // Classifiable / fillable table rows are built in
    // alterStructuredClassifierConfigurationForm().
    $form['classifiable'] = [
      '#type' => 'details',
      '#title' => $this->t('Classifiable Content'),
      '#open' => TRUE,
      '#description' => $this->t('Taxonomy fields classified via structured output. Do not use these placeholders in the free-text prompt. The <em>Vocabularies</em> fieldset configures each vocabulary (description, value source, term metadata for JSON Schema). JSON Schema <code>$defs</code> use the vocabulary machine name. The <em>Fields</em> fieldset maps each entity field to a JSON output key and optional per-field instructions.'),
    ];

    $classifier_settings_parents = ['classifier', 'settings'];
    $form['classifiable']['vocabularies_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vocabularies'),
    ];
    $form['classifiable']['vocabularies_fieldset']['table'] = [
      '#type' => 'table',
      '#parents' => array_merge($classifier_settings_parents, ['classifiable', 'vocabularies']),
    ];

    $form['classifiable']['fields_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fields'),
    ];
    $form['classifiable']['fields_fieldset']['fields'] = [
      '#type' => 'table',
      '#parents' => array_merge($classifier_settings_parents, ['classifiable', 'fields']),
    ];

    $classifiable_fields = $workflow->getEnabledClassifiableFields();
    $form['classifiable']['#access'] = !empty($classifiable_fields);

    $form['fillable'] = [
      '#type' => 'details',
      '#title' => $this->t('Fillable Content'),
      '#open' => TRUE,
      '#description' => $this->t('Plain fields filled from structured output using the placeholders below. <em>Field description</em> is guidance for the model and becomes the JSON Schema <code>description</code> for that output property.'),
    ];

    $form['fillable']['fields'] = [
      '#type' => 'table',
    ];

    $fillable_fields = $workflow->getEnabledFillableFields();
    $form['fillable']['#access'] = !empty($fillable_fields);

    // Completion plugins that support structured output only.
    $completion_plugin_options = [];
    foreach ($this->completionPluginManager->getAvailablePlugins() as $plugin) {
      if ($plugin->hasCapability(CompletionCapability::StructuredOutput)) {
        $completion_plugin_options[$plugin->getPluginId()] = $plugin->getPluginLabel();
      }
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

    $form['inference']['thinking_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Thinking mode'),
      '#description' => $this->t('Thinking mode for the AI model. Higher levels allow more extended reasoning before answering. Not supported by all models.'),
      '#options' => [
        'none' => $this->t('None'),
        'low' => $this->t('Low'),
        'medium' => $this->t('Medium'),
        'high' => $this->t('High'),
      ],
      '#default_value' => $config['inference']['thinking_mode'] ?? 'none',
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
      '#description' => $this->t('Instructions for the model alongside structured output. Only <strong>analyzable</strong> placeholders may appear as <code>{placeholder}</code>; they expand to processed field values. Suffixes: <code>:name</code> for the placeholder machine name (same token as the placeholder key, e.g. for paired XML-style tags), <code>:value</code> or omit for the default. Do not use classifiable or fillable placeholders here. Those fields are described only in the generated JSON Schema. The completion API returns JSON matching that schema.'),
      '#cols' => 100,
      '#rows' => max(15, floor(mb_strlen($prompt) / 100)),
      '#required' => TRUE,
    ];

    // Open the inference group when no plugin is selected yet.
    $form['inference']['#open'] = empty($config['inference']['plugin_id']);

    $form['inference']['plugin_id']['#options'] = $completion_plugin_options;
    $form['inference']['plugin_id']['#description'] = $this->t('Inference plugin with structured output capability.');
    $this->alterStructuredClassifierConfigurationForm($form, $form_state);

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
      if (empty($fields[$category])) {
        $form_state->setValue(array_merge($parents, [$category, 'fields']), []);
      }
    }

    $vocabularies = $form_state->getValue(array_merge($parents, ['classifiable', 'vocabularies'])) ?: [];
    if (empty($vocabularies)) {
      $form_state->setValue(array_merge($parents, ['classifiable', 'vocabularies']), []);
    }

    $error_messages = $this->generateStructuredPromptErrorMessages($prompt, $fields, $entity_type_id, $bundle);
    if (!empty($error_messages)) {
      $prompt_element_name = implode('][', array_merge($parents, ['inference', 'prompt']));
      foreach ($error_messages as $error_message) {
        $form_state->setErrorByName($prompt_element_name, $error_message);
      }
    }

    $plugin_id = $form_state->getValue(array_merge($parents, ['inference', 'plugin_id'])) ?: '';
    if (!empty($plugin_id)) {
      $plugin = $this->completionPluginManager->getPlugin($plugin_id);
      if (!$plugin->hasCapability(CompletionCapability::StructuredOutput)) {
        $plugin_element_name = implode('][', array_merge($parents, ['inference', 'plugin_id']));
        $form_state->setErrorByName($plugin_element_name, (string) $this->t('Selected inference plugin does not support structured output.'));
      }
    }
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
    $query->sort('weight', 'ASC');
    $query->sort('name', 'ASC');

    $ids = $query->execute();
    $terms = $storage->loadMultiple($ids);

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
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', $vocabulary);

    $allowed_types = ['string', 'integer'];
    $disallowed_fields = ['moderation_status', 'weight', 'parent'];

    $properties = [];

    foreach ($field_definitions as $field_name => $field_definition) {
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
      $table = $entity_type->getDataTable();
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
    $options = [];

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
   * Classify an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return ?array
   *   The list of the entity fields that were updated if the classification was
   *   successful, NULL otherwise.
   */
  public function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): ?array {
    return $this->queryModel($entity, $workflow);
  }

  /**
   * Prepare an entity for classification.
   *
   * Also removes analyzable file items not supported (or too large) for the
   * configured completion plugin, after the parent clone and alter hooks.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Prepared entity.
   */
  protected function prepareEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): ContentEntityInterface {
    $prepared_entity = parent::prepareEntity($entity, $workflow);

    // Retrieve the AI plugin.
    $ai_plugin_id = $this->getPluginSetting('inference.plugin_id');
    $ai_plugin = $this->completionPluginManager->getPlugin($ai_plugin_id);
    $supported_file_types = $ai_plugin->getSupportedFileTypes();

    // Filter analyzable fields that are to be passed as files.
    $analyzable_fields = $this->getEnabledFields('analyzable');
    foreach ($analyzable_fields as $field_name => $field_info) {
      if (empty($field_info['file']) || empty($field_info['processor'])) {
        continue;
      }

      if (!$prepared_entity->hasField($field_name) || $prepared_entity->get($field_name)->isEmpty()) {
        continue;
      }

      // Filter out files that are not supported or too large.
      $processor_plugin = $this->analyzableFieldProcessorPluginManager->createInstance($field_info['processor']);
      $processor_plugin->filterFiles($prepared_entity->get($field_name), $supported_file_types);
    }

    return $prepared_entity;
  }

  /**
   * Check that the entity has the data necessary for the classification.
   *
   * Note: this is called before the actual classification.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to classify.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   *
   * @return bool
   *   TRUE if the entity can be processed.
   *
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationFailedException
   *   If the entity doesn't have the data necessary for the classification.
   */
  public function validateEntityData(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): bool {
    $invalid = FALSE;

    // Get a list of the missing analyzable data.
    $analyzable_fields = $this->getEnabledFields('analyzable');
    $empty_fields = [];
    foreach ($analyzable_fields as $field_name => $field_info) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        $empty_fields[$field_name] = TRUE;
      }
    }

    // Basic invalidity is that there is no data to analyze.
    $invalid = count($empty_fields) === count($analyzable_fields);

    if (!$invalid) {
      // Allow other module to do their own validation of the data.
      $context = ['entity' => $entity, 'classifier' => $this];
      $this->moduleHandler->alter(
        'ocha_content_classification_validate_entity_data',
        $invalid,
        $workflow,
        $context,
      );
    }

    if ($invalid) {
      throw new ClassificationFailedException(strtr('No valid data for classification for @bundle_label @entity_id', [
        '@bundle_label' => EntityHelper::getBundleLabelFromEntity($entity),
        '@entity_id' => $entity->id(),
      ]));
    }
    return TRUE;
  }

  /**
   * Queries the completion plugin using structured output (JSON Schema).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param ?string $system_prompt
   *   Optional system prompt. If not set, it will be retrieved from the config.
   * @param ?string $prompt
   *   Optional prompt. If not set, it will be retrieved from the config.
   * @param ?array $enabled_fields
   *   List of enabled fields for the workflow. This can be used to override the
   *   entity fields to use for the inference and to populate after it. This
   *   should contain data as returned by ::getEnabledFields() of this
   *   classifier.
   *
   * @return array
   *   The list of entity fields that were updated after applying structured
   *   output to the entity.
   *
   * @throws \Drupal\ocha_content_classification\Exception\ClassificationFailedException
   *   If the entity has no valid data to analyze.
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If the selected completion plugin does not support structured output, or
   *   from term/value validation during output handling.
   * @throws \Drupal\ocha_content_classification\Exception\ClassifierPluginException
   *   If the structured completion request fails.
   * @throws \Drupal\ocha_content_classification\Exception\UnexpectedValueException
   *   If the model output cannot be applied to the entity fields.
   */
  public function queryModel(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ?string $system_prompt = NULL,
    ?string $prompt = NULL,
    ?array $enabled_fields = NULL,
  ): array {
    $system_prompt ??= $this->getPluginSetting('inference.system_prompt', '', FALSE);
    $prompt ??= $this->getPluginSetting('inference.prompt', '', FALSE);

    $enabled_fields ??= [
      'analyzable' => $this->getEnabledFields('analyzable'),
      'classifiable' => $this->getEnabledFields('classifiable'),
      'fillable' => $this->getEnabledFields('fillable'),
    ];

    $fields = [];
    foreach ($enabled_fields as $type => $field_list) {
      foreach ($field_list as $field_name => $field_info) {
        $merged = $field_info + [
          'name' => $field_name,
          'type' => $type,
        ];
        if ($type === 'classifiable') {
          $merged['min'] = $field_info['min'] ?? $workflow->getClassifiableFieldMin($field_name);
          $merged['max'] = $field_info['max'] ?? $workflow->getClassifiableFieldMax($field_name);
        }
        $fields[$field_info['placeholder']] = $merged;
      }
    }

    $prepared_entity = $this->prepareEntity($entity, $workflow);
    $this->validateEntityData($prepared_entity, $workflow);

    $analyzable_fields_for_prompt = array_filter(
      $fields,
      static fn(array $field): bool => ($field['type'] ?? '') === 'analyzable',
    );
    $prompt = $this->preparePrompt($prompt, $prepared_entity, $analyzable_fields_for_prompt);

    $files = $this->prepareFiles($prepared_entity, $fields);

    $parameters = [
      'temperature' => (float) $this->getPluginSetting('inference.temperature'),
      'top_p' => (float) $this->getPluginSetting('inference.top_p'),
      'max_tokens' => (int) $this->getPluginSetting('inference.max_tokens'),
      'thinking_mode' => (string) $this->getPluginSetting('inference.thinking_mode', 'none'),
    ];

    $plugin_id = $this->getPluginSetting('inference.plugin_id');
    $plugin = $this->completionPluginManager->getPlugin($plugin_id);
    if (!$plugin->hasCapability(CompletionCapability::StructuredOutput)) {
      throw new InvalidConfigurationException(strtr('Inference plugin "@plugin_id" does not support structured output.', [
        '@plugin_id' => $plugin_id,
      ]));
    }

    $json_schema = $this->buildStructuredJsonSchema($prepared_entity, $workflow, $enabled_fields);

    $output = $plugin->queryStructured($prompt, $json_schema, $system_prompt, $parameters, $files);
    if ($output === NULL) {
      throw new ClassifierPluginException('AI structured response error.');
    }

    return $this->applyStructuredOutput($entity, $workflow, $enabled_fields, $output);
  }

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
    $prompt_errors = $this->generateStructuredPromptErrorMessages(
      $prompt,
      $fields,
      $entity->getEntityTypeId(),
      $entity->bundle(),
    );
    if (!empty($prompt_errors)) {
      throw new InvalidConfigurationException(strtr('Invalid structured classifier prompt for @bundle_label @id, skipping.', [
        '@bundle_label' => $bundle_label,
        '@id' => $entity->id() ?? 'new entity',
      ]));
    }

    return TRUE;
  }

  /**
   * Populates classifiable and fillable configuration table rows.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function alterStructuredClassifierConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $workflow = $form_state->getFormObject()?->getEntity();
    if (!isset($workflow) || !($workflow instanceof ClassificationWorkflowInterface)) {
      return;
    }

    $config = $this->getConfiguration();
    $entity_type_id = $workflow->getTargetEntityTypeId();
    $bundle = $workflow->getTargetBundle();
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    $classifiable_fields = $workflow->getEnabledClassifiableFields();
    $vocabularies_config = $config['classifiable']['vocabularies'] ?? [];
    $settings_parents = ['classifier', 'settings'];

    $form['classifiable']['vocabularies_fieldset']['table']['#header'] = [
      ['data' => $this->t('Vocabulary'), 'style' => 'width: 14%'],
      ['data' => $this->t('Description'), 'style' => 'width: 22%'],
      ['data' => $this->t('Value'), 'style' => 'width: 14%'],
      ['data' => $this->t('Term settings'), 'style' => 'width: 50%'],
    ];

    $vocabularies_seen = [];
    foreach ($classifiable_fields as $field_name => $field_info) {
      $field_definition = $field_definitions[$field_name];
      $vocabulary = $this->getFieldVocabulary($field_definition);
      if ($vocabulary === '' || isset($vocabularies_seen[$vocabulary])) {
        continue;
      }
      $vocabularies_seen[$vocabulary] = TRUE;

      $vocabulary_label = $this->entityTypeManager
        ->getStorage('taxonomy_vocabulary')
        ->load($vocabulary)
        ?->label() ?? $vocabulary;
      $value_options = $this->getVocabularyValueSelectOptions($vocabulary);

      $raw_vocabulary = $vocabularies_config[$vocabulary] ?? [];
      $default_value_key = $raw_vocabulary['value'] ?? $raw_vocabulary['property'] ?? 'name';
      if (!isset($value_options[$default_value_key])) {
        $default_value_key = 'name';
      }

      $vocabulary_description_default = $raw_vocabulary['description'] ?? '';

      $allows_empty_selection = $this->taxonomyVocabularyAllowsEmptySelection(
        $workflow,
        $field_definitions,
        $vocabulary,
      );

      // Value field name for #states (must match table #parents + row key).
      $value_input_name = 'classifier[settings][classifiable][vocabularies][' . $vocabulary . '][value]';
      $states_hide_when_custom = [
        'invisible' => [
          ':input[name="' . $value_input_name . '"]' => ['value' => self::VALUE_CUSTOM],
        ],
      ];
      $states_show_when_custom = [
        'visible' => [
          ':input[name="' . $value_input_name . '"]' => ['value' => self::VALUE_CUSTOM],
        ],
      ];

      $row = [
        'vocabulary_label' => [
          '#plain_text' => $vocabulary_label,
        ],
        'description' => [
          '#type' => 'textarea',
          '#title' => $this->t('Description'),
          '#title_display' => 'invisible',
          '#default_value' => $vocabulary_description_default,
          '#cols' => 36,
          '#rows' => max(2, (int) floor(mb_strlen($vocabulary_description_default) / 36) ?: 2),
          '#description' => NULL,
        ],
        'value' => [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#title_display' => 'invisible',
          '#options' => $value_options,
          '#default_value' => $default_value_key,
          '#description' => NULL,
        ],
        'term_settings' => [
          '#type' => 'details',
          '#title' => $this->t('Term settings'),
          '#open' => FALSE,
        ],
      ];

      $title_options = $this->getTermMetadataSourceOptions($vocabulary, FALSE);
      $description_options = $this->getTermMetadataSourceOptions($vocabulary, TRUE);
      $row['term_settings']['non_custom_term_metadata'] = [
        '#type' => 'container',
        '#states' => $states_hide_when_custom,
      ];
      $row['term_settings']['non_custom_term_metadata']['term_title_source'] = [
        '#type' => 'select',
        '#title' => $this->t('Title'),
        '#options' => $title_options,
        '#default_value' => $raw_vocabulary['term_title_source'] ?? 'none',
        '#parents' => array_merge($settings_parents, ['classifiable', 'vocabularies', $vocabulary, 'term_title_source']),
      ];
      $row['term_settings']['non_custom_term_metadata']['term_description_source'] = [
        '#type' => 'select',
        '#title' => $this->t('Description'),
        '#options' => $description_options,
        '#default_value' => $raw_vocabulary['term_description_source'] ?? 'none',
        '#parents' => array_merge($settings_parents, [
          'classifiable',
          'vocabularies',
          $vocabulary,
          'term_description_source',
        ]),
      ];

      $row['term_settings']['custom_term_overrides'] = [
        '#type' => 'container',
        '#states' => $states_show_when_custom,
      ];
      if ($this->vocabularyAllowsTermDescriptionUi($vocabulary)) {
        $terms = $this->getTaxonomyTerms($vocabulary, self::TERM_LIMIT);
        $row['term_settings']['custom_term_overrides']['terms'] = [
          '#type' => 'container',
          '#parents' => array_merge($settings_parents, ['classifiable', 'vocabularies', $vocabulary, 'terms']),
        ];
        $index = 1;
        foreach ($terms as $tid => $term) {
          $row_data = $raw_vocabulary['terms'][$tid] ?? [];
          if (is_string($row_data)) {
            $row_data = [];
          }
          $default_include = array_key_exists('include', $row_data) ? !empty($row_data['include']) : TRUE;
          $default_val = $row_data['value'] ?? $term->getName();
          $default_title = $row_data['title'] ?? '';
          $default_desc = $row_data['description'] ?? (
            $term->hasField('description') && !$term->get('description')->isEmpty()
              ? trim((string) $term->get('description')->value)
              : ''
          );
          $row['term_settings']['custom_term_overrides']['terms'][$tid] = $this->buildCustomTermOverrideFieldset(
            $index,
            $term->label(),
            $default_include,
            $default_val,
            $default_title,
            $default_desc,
          );
          $index++;
        }
        if ($allows_empty_selection) {
          $empty_defaults = $this->getEmptySelectionTermFormDefaults($raw_vocabulary['terms']['empty'] ?? []);
          $row['term_settings']['custom_term_overrides']['terms']['empty'] = $this->buildCustomTermOverrideFieldset(
            $index,
            $this->t('Empty selection option'),
            $empty_defaults['include'],
            $empty_defaults['value'],
            $empty_defaults['title'],
            $empty_defaults['description'],
          );
        }
      }
      else {
        if ($allows_empty_selection) {
          $row['term_settings']['custom_term_overrides']['terms'] = [
            '#type' => 'container',
            '#parents' => array_merge($settings_parents, ['classifiable', 'vocabularies', $vocabulary, 'terms']),
          ];
          $empty_defaults = $this->getEmptySelectionTermFormDefaults($raw_vocabulary['terms']['empty'] ?? []);
          $row['term_settings']['custom_term_overrides']['terms']['empty'] = $this->buildCustomTermOverrideFieldset(
            1,
            $this->t('Empty selection option'),
            $empty_defaults['include'],
            $empty_defaults['value'],
            $empty_defaults['title'],
            $empty_defaults['description'],
          );
        }
        $row['term_settings']['custom_term_overrides']['na'] = [
          '#markup' => '<p>' . $this->t('N/A (vocabulary has too many terms).') . '</p>',
        ];
      }

      $form['classifiable']['vocabularies_fieldset']['table'][$vocabulary] = $row;
    }

    // --- Fields table ---
    $form['classifiable']['fields_fieldset']['fields']['#header'] = [
      ['data' => $this->t('Field'), 'style' => 'width: 15%'],
      ['data' => $this->t('Placeholder'), 'style' => 'width: 20%', 'class' => ['required-mark']],
      ['data' => $this->t('Field description'), 'style' => 'width: 35%'],
      ['data' => $this->t('Min'), 'style' => 'width: 5%'],
      ['data' => $this->t('Max'), 'style' => 'width: 5%'],
      ['data' => $this->t('Vocabulary'), 'style' => 'width: 20%'],
    ];

    foreach ($classifiable_fields as $field_name => $field_info) {
      $field_definition = $field_definitions[$field_name];
      $field_min = $field_info['min'] ?? 0;
      $field_max = $field_info['max'] ?? 1;
      $vocabulary = $this->getFieldVocabulary($field_definition);
      $vocabulary_label = $this->entityTypeManager
        ->getStorage('taxonomy_vocabulary')
        ->load($vocabulary)
        ?->label() ?? $vocabulary;

      $field_desc_default = $config['classifiable']['fields'][$field_name]['description'] ?? '';

      $form['classifiable']['fields_fieldset']['fields'][$field_name] = [
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
        'description' => [
          '#type' => 'textarea',
          '#title' => $this->t('Field description'),
          '#title_display' => 'invisible',
          '#default_value' => $field_desc_default,
          '#cols' => 40,
          '#rows' => max(2, (int) floor(mb_strlen($field_desc_default) / 40) ?: 2),
          '#description' => NULL,
        ],
        'min' => [
          '#plain_text' => $field_min,
        ],
        'max' => [
          '#plain_text' => $field_max,
        ],
        'vocabulary' => [
          '#plain_text' => $vocabulary_label,
        ],
      ];
    }

    $form['fillable']['fields']['#header'] = [
      ['data' => $this->t('Field'), 'style' => 'width: 18%'],
      ['data' => $this->t('Property'), 'style' => 'width: 18%'],
      ['data' => $this->t('Placeholder'), 'style' => 'width: 22%', 'class' => ['required-mark']],
      ['data' => $this->t('Field description'), 'style' => 'width: 42%'],
    ];

    $fillable_fields = $workflow->getEnabledFillableFields();
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
        $fill_desc_default = $config['fillable']['fields'][$field_name_extended]['description'] ?? '';

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
          'description' => [
            '#type' => 'textarea',
            '#title' => $this->t('Field description'),
            '#title_display' => 'invisible',
            '#default_value' => $fill_desc_default,
            '#cols' => 40,
            '#rows' => max(2, (int) floor(mb_strlen($fill_desc_default) / 40) ?: 2),
            '#description' => NULL,
          ],
        ];
      }
    }
  }

  /**
   * Whether per-term description UI applies for this vocabulary size.
   */
  protected function vocabularyAllowsTermDescriptionUi(string $vocabulary): bool {
    return $this->getTaxonomyTermCount($vocabulary) <= self::TERM_LIMIT;
  }

  /**
   * Whether any classifiable field using the vocabulary allows zero selections.
   *
   * Used to show the "empty selection option" in custom value term settings,
   * matching InferenceClassifier when a field min is zero.
   *
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $field_definitions
   *   Field definitions for the workflow bundle.
   * @param string $vocabulary
   *   Vocabulary machine name.
   *
   * @return bool
   *   TRUE if at least one enabled classifiable field for this vocabulary has
   *   min equal to 0.
   */
  protected function taxonomyVocabularyAllowsEmptySelection(
    ClassificationWorkflowInterface $workflow,
    array $field_definitions,
    string $vocabulary,
  ): bool {
    foreach ($workflow->getEnabledClassifiableFields() as $field_name => $field_info) {
      $definition = $field_definitions[$field_name] ?? NULL;
      if (!$definition instanceof FieldDefinitionInterface) {
        continue;
      }
      if ($this->getFieldVocabulary($definition) !== $vocabulary) {
        continue;
      }
      $field_min = (int) ($field_info['min'] ?? 0);
      if ($field_min === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Parsed defaults for the custom-vocabulary empty-selection form row.
   *
   * @param mixed $stored
   *   Raw config for terms.empty: associative row, legacy string, or other.
   *
   * @return array{include: bool, value: string, title: string, description: string}
   *   Trimmed strings and Include default.
   */
  protected function getEmptySelectionTermFormDefaults(mixed $stored): array {
    if (is_string($stored)) {
      $stored = [];
    }
    elseif (!is_array($stored)) {
      $stored = [];
    }
    return [
      'include' => array_key_exists('include', $stored) ? !empty($stored['include']) : TRUE,
      'value' => trim((string) ($stored['value'] ?? '')),
      'title' => trim((string) ($stored['title'] ?? '')),
      'description' => trim((string) ($stored['description'] ?? '')),
    ];
  }

  /**
   * Form API: fieldset for one custom vocabulary term (or empty-selection) row.
   *
   * @param int $index
   *   Number shown in the section title (1-based ordering in the UI).
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $fieldset_label
   *   Second part of the title (taxonomy term label or a translated label).
   * @param bool $default_include
   *   Default for the "Include" checkbox.
   * @param string|int|float|bool|null $default_val
   *   Default for the Value field.
   * @param string|int|float|bool|null $default_title
   *   Default for the Title field.
   * @param string|int|float|bool|null $default_desc
   *   Default for the Description textarea.
   *
   * @return array<string, mixed>
   *   A fieldset render array.
   */
  protected function buildCustomTermOverrideFieldset(
    int $index,
    string|TranslatableMarkup $fieldset_label,
    bool $default_include,
    string|int|float|bool|null $default_val,
    string|int|float|bool|null $default_title,
    string|int|float|bool|null $default_desc,
  ): array {
    $default_desc = (string) $default_desc;
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('@index. @label', [
        '@index' => $index,
        '@label' => $fieldset_label,
      ]),
      'include' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Include'),
        '#default_value' => $default_include,
      ],
      'value' => [
        '#type' => 'textfield',
        '#title' => $this->t('Value'),
        '#default_value' => $default_val,
        '#size' => 60,
      ],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#default_value' => $default_title,
        '#size' => 60,
      ],
      'description' => [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $default_desc,
        '#cols' => 40,
        '#rows' => max(2, (int) floor(mb_strlen($default_desc) / 40) ?: 2),
      ],
    ];
  }

  /**
   * Maps each term ID to the string used to match model output (const).
   *
   * Non-custom Value: values come from the selected taxonomy field.
   * Custom Value: values come from per-term Value textfields where Include is
   * checked.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param string $field_name
   *   The name of the field being classified.
   * @param array $settings
   *   The per-field settings (placeholder, description). Vocabulary-level
   *   settings are looked up automatically.
   *
   * @return array<int|string, mixed>
   *   Term ID keyed map of property values.
   */
  protected function getClassifiableTermPropertyMap(
    ContentEntityInterface $entity,
    string $field_name,
    array $settings,
  ): array {
    $field_definition = $entity->get($field_name)->getFieldDefinition();
    $vocabulary_settings = $this->getFieldVocabularySettings($field_definition);
    $vocabulary = $this->getFieldVocabulary($field_definition);
    $value_key = $vocabulary_settings['value'] ?? 'name';

    if ($value_key === self::VALUE_CUSTOM) {
      $terms = [];
      $terms_settings = $vocabulary_settings['terms'] ?? [];
      foreach ($terms_settings as $tid => $row) {
        if ($tid === 'empty') {
          continue;
        }
        if (!is_array($row)) {
          continue;
        }
        if (empty($row['include'])) {
          continue;
        }
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
          continue;
        }
        $terms[$tid] = $value;
      }
      $empty_row = $terms_settings['empty'] ?? NULL;
      if (is_array($empty_row) && !empty($empty_row['include'])) {
        $empty_value = trim((string) ($empty_row['value'] ?? ''));
        if ($empty_value !== '') {
          $terms['empty'] = $empty_value;
        }
      }
      return $terms;
    }

    $terms = $this->getTaxonomyTermPropertyValues($vocabulary, $value_key);
    if (empty($terms)) {
      return [];
    }

    foreach ($terms as $tid => $value) {
      if (!is_scalar($value) || trim((string) $value) === '') {
        unset($terms[$tid]);
      }
    }

    return $terms;
  }

  /**
   * Builds JSON Schema $defs for a vocabulary used by a classifiable field.
   *
   * The definition contains an anyOf array of const branches built from the
   * term property map. Per-term descriptions and the vocabulary-level
   * description come from vocabulary config; per-field description is placed
   * on the schema property by the caller.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param string $field_name
   *   The name of a classifiable field referencing the vocabulary.
   * @param array $settings
   *   Per-field settings (placeholder, description).
   *
   * @return array<string, mixed>|null
   *   Definition with optional top-level description and anyOf, or NULL.
   */
  protected function buildClassifiableTermSchemaDef(
    ContentEntityInterface $entity,
    string $field_name,
    array $settings,
  ): ?array {
    $mappings = $this->getClassifiableTermMappings($entity, $field_name, $settings);
    if (empty($mappings['by_value'])) {
      return NULL;
    }

    $field_definition = $entity->get($field_name)->getFieldDefinition();
    $vocabulary = $this->getFieldVocabulary($field_definition);
    $vocabulary_settings = $this->getFieldVocabularySettings($field_definition);
    $value_key = $vocabulary_settings['value'] ?? 'name';
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms_settings = $vocabulary_settings['terms'] ?? [];

    $branches = [];
    foreach ($mappings['by_value'] as $const_value => $tid) {
      $branch = [
        'const' => $const_value,
      ];

      if ($value_key === self::VALUE_CUSTOM) {
        $row = is_array($terms_settings[$tid] ?? NULL) ? $terms_settings[$tid] : [];
        $title = trim((string) ($row['title'] ?? ''));
        $desc = trim((string) ($row['description'] ?? ''));
        if ($title !== '') {
          $branch['title'] = $title;
        }
        if ($desc !== '') {
          $branch['description'] = $desc;
        }
      }
      else {
        if ($tid === 'empty') {
          continue;
        }
        $term = $term_storage->load($tid);
        if ($term instanceof TermInterface) {
          $title_src = $vocabulary_settings['term_title_source'] ?? 'none';
          $desc_src = $vocabulary_settings['term_description_source'] ?? 'none';
          $title = $this->resolveTermMetadataString($term, $vocabulary, $title_src);
          $desc = $this->resolveTermMetadataString($term, $vocabulary, $desc_src);
          if ($title !== '') {
            $branch['title'] = $title;
          }
          if ($desc !== '') {
            $branch['description'] = $desc;
          }
        }
      }

      $branches[] = $branch;
    }

    $def = [
      'anyOf' => $branches,
    ];
    $vocabulary_description = trim((string) ($vocabulary_settings['description'] ?? ''));
    if ($vocabulary_description !== '') {
      $def['description'] = $vocabulary_description;
    }

    return $def;
  }

  /**
   * Build the JSON schema used by structured output.
   *
   * Vocabulary-level $defs are deduplicated: multiple classifiable fields that
   * reference the same vocabulary share one $defs entry. Per-field description
   * is placed on the schema property (alongside $ref or on the array wrapper).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow being used.
   * @param array $fields
   *   Enabled fields by category (analyzable, classifiable, fillable).
   *
   * @return array<string, mixed>
   *   The JSON schema.
   */
  protected function buildStructuredJsonSchema(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    array $fields,
  ): array {
    $schema = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [],
      'required' => [],
    ];
    $defs = [];
    $vocabulary_def_names = [];

    foreach ($fields['classifiable'] ?? [] as $field_name => $settings) {
      $placeholder = $settings['placeholder'] ?? '';
      if ($placeholder === '') {
        continue;
      }

      $field_definition = $entity->get($field_name)->getFieldDefinition();
      $vocabulary = $this->getFieldVocabulary($field_definition);

      // Build the $defs entry once per vocabulary (key = vocabulary machine
      // name).
      if (!isset($vocabulary_def_names[$vocabulary])) {
        $term_def = $this->buildClassifiableTermSchemaDef($entity, $field_name, $settings);
        if ($term_def === NULL) {
          continue;
        }

        $def_name = $vocabulary;
        $defs[$def_name] = $term_def;
        $vocabulary_def_names[$vocabulary] = $def_name;
      }
      else {
        $def_name = $vocabulary_def_names[$vocabulary];
      }

      $min = (int) ($settings['min'] ?? $workflow->getClassifiableFieldMin($field_name));
      $max = (int) ($settings['max'] ?? $workflow->getClassifiableFieldMax($field_name));

      $field_desc = trim((string) ($settings['description'] ?? ''));
      $single_value = ($max === 1);

      if ($single_value) {
        $property = [
          '$ref' => '#/$defs/' . $def_name,
        ];
        if ($field_desc !== '') {
          $property['description'] = $field_desc;
        }
      }
      else {
        $field_label = (string) $field_definition->getLabel();
        $array_desc = $field_desc !== '' ? $field_desc : (string) $this->t('Selected terms for @field.', [
          '@field' => $field_label,
        ]);
        $property = [
          'type' => 'array',
          'uniqueItems' => TRUE,
          'description' => $array_desc,
          'items' => [
            '$ref' => '#/$defs/' . $def_name,
          ],
        ];
        if ($max >= 0) {
          $property['maxItems'] = $max;
        }
        if ($min > 0) {
          $property['minItems'] = $min;
        }
      }

      if ($min > 0) {
        $schema['required'][] = $placeholder;
      }

      $schema['properties'][$placeholder] = $property;
    }

    if (!empty($defs)) {
      $schema['$defs'] = $defs;
    }

    foreach ($fields['fillable'] ?? [] as $settings) {
      $placeholder = $settings['placeholder'] ?? '';
      if ($placeholder === '') {
        continue;
      }

      $fillable_prop = [
        'type' => 'string',
      ];
      $fill_desc = trim((string) ($settings['description'] ?? ''));
      if ($fill_desc !== '') {
        $fillable_prop['description'] = $fill_desc;
      }
      $schema['properties'][$placeholder] = $fillable_prop;
      $schema['required'][] = $placeholder;
    }

    if (empty($schema['required'])) {
      unset($schema['required']);
    }

    return $schema;
  }

  /**
   * Apply structured output and update the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow being used.
   * @param array $fields
   *   Enabled field configuration passed through from classification.
   * @param array $output
   *   Parsed structured output from the model.
   *
   * @return array<string, mixed>|null
   *   The classified fields.
   *
   * @throws \Drupal\ocha_content_classification\Exception\UnexpectedValueException
   *   If the output is invalid.
   */
  protected function applyStructuredOutput(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    array $fields,
    array $output,
  ): ?array {
    $classified_fields = [];

    $force_update = [];
    foreach (['classifiable', 'fillable'] as $type) {
      foreach ($fields[$type] ?? [] as $field_name => $field_info) {
        $force_update[$field_name] = !empty($field_info['force']);
      }
    }

    $force_update_context = ['entity' => $entity];
    $this->moduleHandler->alter(
      'ocha_content_classification_force_field_update',
      $force_update,
      $workflow,
      $force_update_context,
    );

    foreach ($fields['classifiable'] ?? [] as $field_name => $settings) {
      if (empty($force_update[$field_name]) && !$entity->get($field_name)->isEmpty()) {
        continue;
      }

      $placeholder = $settings['placeholder'] ?? '';
      $selected = $output[$placeholder] ?? NULL;
      if ($selected === NULL) {
        $selected = [];
      }
      elseif (is_string($selected)) {
        $selected = $selected !== '' ? [$selected] : [];
      }
      elseif (!is_array($selected)) {
        throw new UnexpectedValueException(strtr('Invalid structured selection for @field.', [
          '@field' => $entity->get($field_name)->getFieldDefinition()->getLabel(),
        ]));
      }

      $term_mappings = $this->getClassifiableTermMappings($entity, $field_name, $settings);
      $allowed_term_ids_by_value = $term_mappings['by_value'];

      $term_ids = [];
      foreach ($selected as $item) {
        if (!is_scalar($item)) {
          continue;
        }

        $item = trim((string) $item);
        $term_id = $allowed_term_ids_by_value[$item] ?? NULL;
        if ($term_id === 'empty') {
          continue;
        }
        if (!isset($term_id) || !is_int($term_id) || $term_id <= 0) {
          continue;
        }

        $term_ids[$term_id] = $term_id;
      }
      $term_ids = array_values($term_ids);

      $term_id_count = count($term_ids);
      $min = $settings['min'] ?? $workflow->getClassifiableFieldMin($field_name);
      $max = $settings['max'] ?? $workflow->getClassifiableFieldMax($field_name);
      $is_under_min = $term_id_count < $min;
      $is_over_max = $max !== -1 && $term_id_count > $max;

      if ($is_under_min || $is_over_max) {
        $range = $max === -1 ? "at least $min" : "$min-$max";
        throw new UnexpectedValueException(strtr('Number of terms for @field is outside the allowed range (@range).', [
          '@field' => $entity->get($field_name)->getFieldDefinition()->getLabel(),
          '@range' => $range,
        ]));
      }

      $classified_fields['classifiable'][$field_name] = $term_ids;
    }

    foreach ($fields['fillable'] ?? [] as $field_name_extended => $settings) {
      [$field_name, $property] = explode('__', $field_name_extended, 2);

      if (empty($force_update[$field_name_extended]) && !empty($entity->get($field_name)->{$property})) {
        continue;
      }

      $placeholder = $settings['placeholder'] ?? '';
      $field_definition = $entity->get($field_name)->getFieldDefinition();

      $content = $output[$placeholder] ?? '';
      if (!is_scalar($content)) {
        $content = '';
      }

      $content = (string) $content;
      if ($content !== '') {
        $preserve_new_lines = $field_definition->getType() !== 'text';
        $content = TextHelper::sanitizeText($content, $preserve_new_lines);
      }

      if ($content === '') {
        throw new UnexpectedValueException(strtr('Missing content for @field_label - @property_label.', [
          '@field_label' => $field_definition->getLabel(),
          '@property_label' => $this->getFieldPropertyLabel($field_definition, $property),
        ]));
      }

      $classified_fields['fillable'][$field_name][$property] = $content;
    }

    return $this->updateEntity(
      $entity,
      $workflow,
      $this,
      $classified_fields,
      ['output' => json_encode($output, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)],
    );
  }

  /**
   * Get classifiable term mappings keyed by IDs and display values.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param string $field_name
   *   The name of the field being classified.
   * @param array $settings
   *   The settings for the field.
   *
   * @return array{by_id: array<int, string>, by_value: array<string, int|string>}
   *   Mappings keyed by term IDs and by display values. by_value may map a
   *   const string to the sentinel string "empty" for the empty selection.
   *
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   If the field has duplicate selectable values.
   */
  protected function getClassifiableTermMappings(ContentEntityInterface $entity, string $field_name, array $settings): array {
    $terms = $this->getClassifiableTermPropertyMap($entity, $field_name, $settings);

    $by_id = [];
    $by_value = [];
    $duplicates = [];
    foreach ($terms as $id => $label) {
      if (!is_scalar($label)) {
        continue;
      }

      $display_value = trim((string) $label);
      if ($display_value === '') {
        continue;
      }

      if ($id === 'empty') {
        if (isset($by_value[$display_value]) && $by_value[$display_value] !== 'empty') {
          $duplicates[$display_value][] = $by_value[$display_value];
          $duplicates[$display_value][] = 'empty';
        }
        elseif (!isset($by_value[$display_value])) {
          $by_value[$display_value] = 'empty';
        }
        continue;
      }

      $int_id = (int) ((string) $id);
      if ($int_id <= 0) {
        continue;
      }

      if (isset($by_value[$display_value]) && $by_value[$display_value] !== $int_id) {
        $duplicates[$display_value][] = $by_value[$display_value];
        $duplicates[$display_value][] = $int_id;
        continue;
      }

      $by_id[$int_id] = $display_value;
      $by_value[$display_value] = $int_id;
    }

    if (!empty($duplicates)) {
      $field_label = (string) $entity->get($field_name)->getFieldDefinition()->getLabel();
      $duplicate_values = implode(', ', array_keys($duplicates));
      throw new InvalidConfigurationException(strtr('Duplicate selectable values for @field: @values.', [
        '@field' => $field_label,
        '@values' => $duplicate_values,
      ]));
    }

    return [
      'by_id' => $by_id,
      'by_value' => $by_value,
    ];
  }

  /**
   * Builds human-readable validation messages for prompt placeholders.
   *
   * @param string $prompt
   *   The prompt to validate.
   * @param array $fields
   *   The fields to validate.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return string[]
   *   The error messages.
   *
   * @see validateStructuredPrompt()
   */
  protected function generateStructuredPromptErrorMessages(
    string $prompt,
    array $fields,
    string $entity_type_id,
    string $bundle,
  ): array {
    $error_messages = [];

    // Missing prompt.
    if ($prompt === '') {
      $error_messages[] = (string) $this->t('The prompt must not be empty.');
      return $error_messages;
    }

    // Forbidden placeholders in prompt.
    $forbidden_in_prompt = $this->getClassifiableFillablePlaceholdersUsedInPrompt($prompt, $fields);
    if (!empty($forbidden_in_prompt)) {
      $error_messages[] = (string) $this->t('The prompt must not use classifiable or fillable placeholders (@placeholders). Only analyzable placeholders are expanded; taxonomy and fillable fields are communicated via structured output.', [
        '@placeholders' => implode(', ', $forbidden_in_prompt),
      ]);
      return $error_messages;
    }

    // Validate prompt placeholders.
    $prompt_errors = $this->validateStructuredPrompt($prompt, $fields);
    if (empty($prompt_errors)) {
      return $error_messages;
    }

    // Split the map: non-empty values are workflow field names for analyzable
    // fields whose placeholder never appeared in the prompt ("missing").
    // Empty-string values are unknown placeholder names that appeared in the
    // prompt ("extra").
    $missing_placeholders = array_filter($prompt_errors, fn($item) => $item !== '');
    $extra_placeholders = array_filter($prompt_errors, fn($item) => $item === '');

    if (!empty($missing_placeholders)) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      $missing_field_labels = array_map(function ($field_name) use ($field_definitions) {
        return $field_definitions[$field_name]->getLabel();
      }, $missing_placeholders);

      $error_messages[] = (string) $this->formatPlural(
        count($missing_field_labels),
        'Missing placeholder for field: @fields.',
        'Missing placeholders for fields: @fields.',
        ['@fields' => implode(', ', $missing_field_labels)]
      );
    }

    if (!empty($extra_placeholders)) {
      $error_messages[] = (string) $this->formatPlural(
        count($extra_placeholders),
        'Extra placeholder not matching any field: @placeholders.',
        'Extra placeholders not matching any field: @placeholders.',
        ['@placeholders' => implode(', ', array_keys($extra_placeholders))]
      );
    }

    return $error_messages;
  }

  /**
   * Finds classifiable/fillable placeholders in the prompt.
   *
   * @param string $prompt
   *   The prompt to search.
   * @param array $fields
   *   The fields to search.
   *
   * @return string[]
   *   The placeholders found in the prompt.
   */
  protected function getClassifiableFillablePlaceholdersUsedInPrompt(string $prompt, array $fields): array {
    $configured = [];
    foreach (['classifiable', 'fillable'] as $field_type) {
      foreach ($fields[$field_type] ?? [] as $settings) {
        $placeholder = $settings['placeholder'] ?? '';
        if ($placeholder !== '') {
          $configured[$placeholder] = TRUE;
        }
      }
    }
    if ($configured === [] || $prompt === '') {
      return [];
    }
    $used = [];
    if (preg_match_all('/\{([a-z0-9_]+)(?::[^:}]+)?\}/', $prompt, $matches) > 0) {
      foreach (array_unique($matches[1]) as $name) {
        if (isset($configured[$name])) {
          $used[] = $name;
        }
      }
    }
    return $used;
  }

  /**
   * Validates prompt placeholders against configured field placeholders.
   *
   * @return array<string, string>
   *   Keys are placeholder base names (no braces). Values encode two cases:
   *   - Non-empty: the workflow field machine name for a required analyzable
   *     field whose placeholder is still absent from the prompt after scanning.
   *   - Empty string: a placeholder that appears in the prompt but is not
   *     configured on any analyzable, classifiable, or fillable field.
   *   File-backed analyzable placeholders are optional and removed from this
   *   result via $optional_placeholders when missing from the prompt.
   */
  protected function validateStructuredPrompt(string $prompt, array $fields): array {
    if ($prompt === '' || empty($fields)) {
      return [];
    }

    $allowed_placeholders = [];
    $required_placeholders = [];
    $optional_placeholders = [];

    foreach (['analyzable', 'classifiable', 'fillable'] as $field_type) {
      foreach ($fields[$field_type] ?? [] as $field_name => $settings) {
        $placeholder = $settings['placeholder'] ?? '';
        if ($placeholder === '') {
          continue;
        }

        $allowed_placeholders[$placeholder] = $field_name;
        if ($field_type === 'analyzable') {
          $required_placeholders[$placeholder] = $field_name;
          if (!empty($settings['file'])) {
            $optional_placeholders[$placeholder] = TRUE;
          }
        }
      }
    }

    if (empty($allowed_placeholders)) {
      return [];
    }

    if (preg_match_all('/\{([a-z0-9_]+)(?::[^:}]+)?\}/', $prompt, $matches) > 0) {
      foreach (array_unique($matches[1]) as $prompt_placeholder) {
        if (!isset($allowed_placeholders[$prompt_placeholder])) {
          // Mark as "extra": unknown token; value '' is the sentinel for
          // generateStructuredPromptErrorMessages().
          $required_placeholders[$prompt_placeholder] = '';
          continue;
        }
        unset($required_placeholders[$prompt_placeholder]);
      }
    }

    // Drop optional file analyzable placeholders that were never used in the
    // prompt.
    return array_diff_key($required_placeholders, $optional_placeholders);
  }

  /**
   * Prepare the prompt, replacing analyzable field placeholders only.
   *
   * @param string $prompt
   *   Prompt template.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $fields
   *   Analyzable fields keyed by placeholder machine name (each has key "type"
   *   => "analyzable").
   *
   * @return string
   *   Prompt text with analyzable placeholders expanded.
   */
  public function preparePrompt(string $prompt, ContentEntityInterface $entity, array $fields): string {
    if ($prompt === '' || $fields === []) {
      return $prompt;
    }

    $placeholder_pattern = implode('|', array_map('preg_quote', array_keys($fields)));
    if ($placeholder_pattern === '') {
      return $prompt;
    }

    $pattern = "/[{](?<placeholder>(?:{$placeholder_pattern}))(?<modifier>:[^:}]+)?[}]/";

    $prompt = preg_replace_callback($pattern, function ($matches) use ($entity, &$fields) {
      $placeholder = $matches['placeholder'];
      $modifier = $matches['modifier'] ?? '';

      $field = &$fields[$placeholder];
      if (($field['type'] ?? '') !== 'analyzable') {
        return '';
      }
      return match ($modifier) {
        '', ':value' => $this->getAnalyzableFieldValue($entity, $field),
        ':name' => $placeholder,
        default => '',
      };
    }, $prompt);

    return $prompt;
  }

  /**
   * Get the files from analyzable fields to pass the AI.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being classified.
   * @param array $fields
   *   Field configuration keyed by placeholder (includes analyzable file
   *   fields).
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
    if (isset($field['value'])) {
      return $field['value'];
    }

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

    $field['value'] = $this->analyzableFieldProcessorPluginManager
      ->createInstance($field['processor'])
      ->toString($placeholder, $entity->get($field_name));

    // Store the value so that we don't need to recompute the value when calling
    // again this method when preparing the prompt.
    return $field['value'];
  }

  /**
   * Get enabled fields of a given type with a placeholder.
   *
   * @param string $type
   *   Either 'analyzable', 'classifiable' or 'fillable'.
   *
   * @return array<string,mixed>
   *   Associative array of fields and their settings keyed by field names.
   *
   * @throws \Drupal\ocha_content_classification\Exception\InvalidConfigurationException
   *   Exception if the fields settings could not be retrieved.
   */
  public function getEnabledFields(string $type): array {
    $fields = $this->getPluginSetting($type . '.fields');
    return array_filter($fields, fn($settings) => !empty($settings['placeholder']));
  }

  /**
   * Normalizes stored vocabulary settings (legacy keys, defaults).
   */
  protected function normalizeVocabularySettings(array $settings): array {
    $out = $settings;
    if (!isset($out['value']) && isset($out['property'])) {
      $out['value'] = $out['property'];
    }
    if (!isset($out['value']) || $out['value'] === '') {
      $out['value'] = 'name';
    }
    $out['term_title_source'] = $out['term_title_source'] ?? 'none';
    $out['term_description_source'] = $out['term_description_source'] ?? 'none';
    unset($out['schema_def'], $out['property']);
    return $out;
  }

  /**
   * Select options for the vocabulary Value column (custom + taxonomy fields).
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   Select options for the vocabulary Value (taxonomy fields and custom).
   */
  protected function getVocabularyValueSelectOptions(string $vocabulary): array {
    $options = $this->getTaxonomyProperties($vocabulary);
    $options[self::VALUE_CUSTOM] = $this->t('Custom');
    return $options;
  }

  /**
   * Options for JSON Schema title/description sources (non-custom mode).
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param bool $include_description_key
   *   Include the term description body as "description".
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   Options for JSON Schema title/description sources (non-custom mode).
   */
  protected function getTermMetadataSourceOptions(string $vocabulary, bool $include_description_key): array {
    $options = [
      'none' => $this->t('- None -'),
      'name' => $this->t('Name'),
    ] + $this->getTaxonomyProperties($vocabulary);
    if ($include_description_key) {
      $options['description'] = $this->t('Description (term)');
    }
    return $options;
  }

  /**
   * Resolves a title or description string from a term for JSON Schema.
   */
  protected function resolveTermMetadataString(TermInterface $term, string $vocabulary, string $source): string {
    if ($source === 'none' || $source === '') {
      return '';
    }
    if ($source === 'name') {
      return $term->getName();
    }
    if ($source === 'description') {
      if ($term->hasField('description') && !$term->get('description')->isEmpty()) {
        return trim((string) $term->get('description')->value);
      }
      return '';
    }
    $map = $this->getTaxonomyTermPropertyValues($vocabulary, $source);
    $tid = (int) $term->id();
    $v = $map[$tid] ?? NULL;
    return is_scalar($v) ? trim((string) $v) : '';
  }

  /**
   * Get vocabulary-level settings for classifiable fields.
   *
   * @return array<string, array>
   *   Settings keyed by vocabulary machine name.
   */
  protected function getVocabularySettings(): array {
    return $this->getPluginSetting('classifiable.vocabularies', [], FALSE) ?? [];
  }

  /**
   * Get the vocabulary settings for a specific classifiable field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   Normalized vocabulary settings, or empty.
   */
  protected function getFieldVocabularySettings(FieldDefinitionInterface $field_definition): array {
    $vocabulary = $this->getFieldVocabulary($field_definition);
    if ($vocabulary === '') {
      return [];
    }
    $all = $this->getVocabularySettings();
    return $this->normalizeVocabularySettings($all[$vocabulary] ?? []);
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
   * @param string $property
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
