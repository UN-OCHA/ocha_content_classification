<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_content_classification\Unit\Plugin\OchaContentClassifier;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface;
use Drupal\ocha_content_classification\Plugin\OchaContentClassifier\StructuredInferenceClassifier;
use Drupal\ocha_content_classification\Service\ClassificationSimulationContext;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for fillable maxLength schema and apply truncation.
 *
 * @coversDefaultClass \Drupal\ocha_content_classification\Plugin\OchaContentClassifier\StructuredInferenceClassifier
 * @group ocha_content_classification
 */
class StructuredInferenceClassifierFillableMaxLengthTest extends UnitTestCase {

  /**
   * Test classifier exposing protected schema/apply methods.
   */
  protected TestStructuredInferenceClassifier $classifier;

  /**
   * Logger mock.
   */
  protected LoggerInterface $logger;

  /**
   * Module handler mock.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Simulation context (final; use real instance).
   */
  protected ClassificationSimulationContext $simulationContext;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->simulationContext = new ClassificationSimulationContext();
    // Skip post-classify hooks during apply/updateEntity in these unit tests.
    $this->simulationContext->enter();

    $this->classifier = new TestStructuredInferenceClassifier(
      [],
      'structured_inference',
      ['id' => 'structured_inference', 'label' => 'Structured'],
      $this->createMock(ConfigFactoryInterface::class),
      $logger_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(Connection::class),
      $this->moduleHandler,
      $this->createMock(AnalyzableFieldProcessorPluginManagerInterface::class),
      $this->simulationContext,
      $this->createMock(CompletionPluginManagerInterface::class),
    );
    $this->classifier->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * @covers ::buildStructuredJsonSchema
   */
  public function testSchemaIncludesMaxLengthAndDescriptionNote(): void {
    $entity = $this->createEntityWithFillableFields([
      'title' => ['max_length' => 255, 'type' => 'string'],
    ]);
    $workflow = $this->createMock(ClassificationWorkflowInterface::class);
    $fields = [
      'fillable' => [
        'title__value' => [
          'placeholder' => 'title',
          'description' => 'Document title.',
        ],
      ],
    ];

    $this->moduleHandler->expects($this->once())->method('alter');

    $schema = $this->classifier->exposeBuildStructuredJsonSchema($entity, $workflow, $fields);

    $this->assertSame('string', $schema['properties']['title']['type']);
    $this->assertSame(255, $schema['properties']['title']['maxLength']);
    $this->assertStringContainsString('Document title.', $schema['properties']['title']['description']);
    $this->assertStringContainsString('Maximum length: 255 characters.', $schema['properties']['title']['description']);
  }

  /**
   * @covers ::buildStructuredJsonSchema
   */
  public function testSchemaOmitsMaxLengthWhenUnset(): void {
    $entity = $this->createEntityWithFillableFields([
      'body' => ['max_length' => NULL, 'type' => 'text_long'],
    ]);
    $workflow = $this->createMock(ClassificationWorkflowInterface::class);
    $fields = [
      'fillable' => [
        'body__value' => [
          'placeholder' => 'body',
          'description' => 'Body text.',
        ],
      ],
    ];

    $schema = $this->classifier->exposeBuildStructuredJsonSchema($entity, $workflow, $fields);

    $this->assertArrayNotHasKey('maxLength', $schema['properties']['body']);
    $this->assertSame('Body text.', $schema['properties']['body']['description']);
  }

  /**
   * @covers ::applyStructuredOutput
   */
  public function testApplyTruncatesOversizedFillableValue(): void {
    $long_title = str_repeat('Headline word ', 40);
    $this->assertGreaterThan(255, mb_strlen($long_title));

    $captured = NULL;
    $entity = $this->createEntityForApply([
      'title' => ['max_length' => 255, 'type' => 'string'],
    ], $captured);

    $workflow = $this->createMock(ClassificationWorkflowInterface::class);
    $fields = [
      'fillable' => [
        'title__value' => [
          'placeholder' => 'title',
          'force' => TRUE,
        ],
      ],
    ];
    $output = ['title' => $long_title];

    $this->logger->expects($this->once())->method('warning');

    $updated = $this->classifier->exposeApplyStructuredOutput($entity, $workflow, $fields, $output);

    $this->assertContains('title', $updated);
    $this->assertIsString($captured);
    $this->assertLessThanOrEqual(255, mb_strlen($captured));
    $this->assertStringContainsString('…', $captured);
  }

  /**
   * @covers ::applyStructuredOutput
   */
  public function testApplyLeavesInLimitValueUnchanged(): void {
    $title = 'A title within the limit';
    $captured = NULL;
    $entity = $this->createEntityForApply([
      'title' => ['max_length' => 255, 'type' => 'string'],
    ], $captured);

    $workflow = $this->createMock(ClassificationWorkflowInterface::class);
    $fields = [
      'fillable' => [
        'title__value' => [
          'placeholder' => 'title',
          'force' => TRUE,
        ],
      ],
    ];

    $this->logger->expects($this->never())->method('warning');

    $this->classifier->exposeApplyStructuredOutput($entity, $workflow, $fields, ['title' => $title]);
    $this->assertSame($title, $captured);
  }

  /**
   * @covers ::applyStructuredOutput
   */
  public function testApplyLeavesUnconstrainedFieldUnchanged(): void {
    $body = str_repeat('Paragraph without a max length setting. ', 30);
    $captured = NULL;
    $entity = $this->createEntityForApply([
      'body' => ['max_length' => NULL, 'type' => 'text_long'],
    ], $captured);

    $workflow = $this->createMock(ClassificationWorkflowInterface::class);
    $fields = [
      'fillable' => [
        'body__value' => [
          'placeholder' => 'body',
          'force' => TRUE,
        ],
      ],
    ];

    $this->logger->expects($this->never())->method('warning');

    $this->classifier->exposeApplyStructuredOutput($entity, $workflow, $fields, ['body' => $body]);
    $this->assertSame(trim(preg_replace('/\s+/u', ' ', $body)), $captured);
  }

  /**
   * Build an entity for schema tests.
   *
   * @param array<string, array{max_length: int|null, type: string}> $fields
   *   Field stubs keyed by field name.
   */
  protected function createEntityWithFillableFields(array $fields): ContentEntityInterface {
    $map = [];
    foreach ($fields as $field_name => $info) {
      $storage = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage->method('getSetting')->with('max_length')->willReturn($info['max_length']);

      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getFieldStorageDefinition')->willReturn($storage);
      $definition->method('getType')->willReturn($info['type']);
      $definition->method('getLabel')->willReturn($field_name);

      $field_list = $this->createMock(FieldItemListInterface::class);
      $field_list->method('getFieldDefinition')->willReturn($definition);
      $map[$field_name] = $field_list;
    }

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('get')->willReturnCallback(static fn(string $name) => $map[$name]);
    return $entity;
  }

  /**
   * Build an entity for apply tests that captures the value passed to set().
   *
   * @param array<string, array{max_length: int|null, type: string}> $fields
   *   Field stubs keyed by field name.
   * @param string|null $captured
   *   Receives the value written to the fillable property.
   */
  protected function createEntityForApply(array $fields, ?string &$captured): ContentEntityInterface {
    $map = [];
    foreach ($fields as $field_name => $info) {
      $storage = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage->method('getSetting')->with('max_length')->willReturn($info['max_length']);

      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getFieldStorageDefinition')->willReturn($storage);
      $definition->method('getType')->willReturn($info['type']);
      $definition->method('getLabel')->willReturn($field_name);

      $field_item = $this->createMock(FieldItemInterface::class);
      $field_item->method('set')->willReturnCallback(function ($property, $value) use (&$captured, $field_item) {
        if ($property === 'value') {
          $captured = $value;
        }
        return $field_item;
      });

      $field_list = $this->createMock(FieldItemListInterface::class);
      $field_list->method('getFieldDefinition')->willReturn($definition);
      $field_list->method('first')->willReturn($field_item);
      $map[$field_name] = $field_list;
    }

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(1);
    $entity->method('get')->willReturnCallback(static fn(string $name) => $map[$name]);
    return $entity;
  }

}

/**
 * Test double exposing protected StructuredInferenceClassifier methods.
 */
class TestStructuredInferenceClassifier extends StructuredInferenceClassifier {

  /**
   * Expose buildStructuredJsonSchema().
   */
  public function exposeBuildStructuredJsonSchema(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    array $fields,
  ): array {
    return $this->buildStructuredJsonSchema($entity, $workflow, $fields);
  }

  /**
   * Expose applyStructuredOutput().
   */
  public function exposeApplyStructuredOutput(
    ContentEntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    array $fields,
    array $output,
  ): ?array {
    return $this->applyStructuredOutput($entity, $workflow, $fields, $output);
  }

}
