<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_content_classification\Unit\Plugin;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Plugin\AnalyzableFieldProcessorPluginManagerInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginBase;
use Drupal\ocha_content_classification\Service\ClassificationSimulationContext;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for fillable max-length helpers on ClassifierPluginBase.
 *
 * @coversDefaultClass \Drupal\ocha_content_classification\Plugin\ClassifierPluginBase
 * @group ocha_content_classification
 */
class ClassifierPluginBaseFillableMaxLengthTest extends UnitTestCase {

  /**
   * Test classifier exposing protected max-length helpers.
   */
  protected TestClassifierPlugin $classifier;

  /**
   * Logger mock.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $this->classifier = new TestClassifierPlugin(
      [],
      'test_classifier',
      ['id' => 'test_classifier', 'label' => 'Test'],
      $this->createMock(ConfigFactoryInterface::class),
      $logger_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(AnalyzableFieldProcessorPluginManagerInterface::class),
      new ClassificationSimulationContext(),
    );
  }

  /**
   * @covers ::getFillablePropertyMaxLength
   */
  public function testGetFillablePropertyMaxLengthReturnsPositiveInt(): void {
    $definition = $this->createFieldDefinition(255);
    $this->assertSame(255, $this->classifier->exposeGetFillablePropertyMaxLength($definition, 'value'));
  }

  /**
   * @covers ::getFillablePropertyMaxLength
   */
  public function testGetFillablePropertyMaxLengthReturnsNullWhenUnset(): void {
    $definition = $this->createFieldDefinition(NULL);
    $this->assertNull($this->classifier->exposeGetFillablePropertyMaxLength($definition, 'value'));
  }

  /**
   * @covers ::getFillablePropertyMaxLength
   */
  public function testGetFillablePropertyMaxLengthReturnsNullWhenZero(): void {
    $definition = $this->createFieldDefinition(0);
    $this->assertNull($this->classifier->exposeGetFillablePropertyMaxLength($definition, 'value'));
  }

  /**
   * @covers ::enforceFillablePropertyMaxLength
   */
  public function testEnforceTruncatesOversizedValueAndLogsWarning(): void {
    $value = str_repeat('word ', 60);
    $this->assertGreaterThan(255, mb_strlen($value));

    $entity = $this->createEntityWithFieldMaxLength('node', 42, 'title', 255);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Truncated fillable value'),
        $this->callback(function (array $context) use ($value): bool {
          return $context['@entity_type'] === 'node'
            && (string) $context['@entity_id'] === '42'
            && $context['@field'] === 'title'
            && $context['@property'] === 'value'
            && (string) $context['@original_length'] === (string) mb_strlen($value)
            && (string) $context['@max_length'] === '255';
        }),
      );

    $result = $this->classifier->exposeEnforceFillablePropertyMaxLength($entity, 'title', 'value', $value);
    $this->assertLessThanOrEqual(255, mb_strlen($result));
    $this->assertStringContainsString('…', $result);
    $this->assertNotSame($value, $result);
  }

  /**
   * @covers ::enforceFillablePropertyMaxLength
   */
  public function testEnforceLeavesInLimitValueUnchanged(): void {
    $value = 'Short title within limit';
    $entity = $this->createEntityWithFieldMaxLength('node', 7, 'title', 255);

    $this->logger->expects($this->never())->method('warning');

    $result = $this->classifier->exposeEnforceFillablePropertyMaxLength($entity, 'title', 'value', $value);
    $this->assertSame($value, $result);
  }

  /**
   * @covers ::enforceFillablePropertyMaxLength
   */
  public function testEnforceLeavesValueUnchangedWithoutMaxLength(): void {
    $value = str_repeat('Long body text without a storage max length. ', 20);
    $entity = $this->createEntityWithFieldMaxLength('node', 9, 'body', NULL);

    $this->logger->expects($this->never())->method('warning');

    $result = $this->classifier->exposeEnforceFillablePropertyMaxLength($entity, 'body', 'value', $value);
    $this->assertSame($value, $result);
  }

  /**
   * Build a field definition stub with optional storage max_length.
   */
  protected function createFieldDefinition(int|null $max_length): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getSetting')->with('max_length')->willReturn($max_length);

    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getFieldStorageDefinition')->willReturn($storage);
    return $definition;
  }

  /**
   * Build an entity stub whose named field has the given storage max_length.
   */
  protected function createEntityWithFieldMaxLength(
    string $entity_type_id,
    int $entity_id,
    string $field_name,
    int|null $max_length,
  ): ContentEntityInterface {
    $definition = $this->createFieldDefinition($max_length);

    $field_list = $this->createMock(FieldItemListInterface::class);
    $field_list->method('getFieldDefinition')->willReturn($definition);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entity_type_id);
    $entity->method('id')->willReturn($entity_id);
    $entity->method('get')->with($field_name)->willReturn($field_list);
    return $entity;
  }

}

/**
 * Test double exposing ClassifierPluginBase max-length helpers.
 */
class TestClassifierPlugin extends ClassifierPluginBase {

  /**
   * {@inheritdoc}
   */
  public function classifyEntity(ContentEntityInterface $entity, ClassificationWorkflowInterface $workflow): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity): bool {
    return TRUE;
  }

  /**
   * Expose getFillablePropertyMaxLength().
   */
  public function exposeGetFillablePropertyMaxLength(FieldDefinitionInterface $field_definition, string $property): ?int {
    return $this->getFillablePropertyMaxLength($field_definition, $property);
  }

  /**
   * Expose enforceFillablePropertyMaxLength().
   */
  public function exposeEnforceFillablePropertyMaxLength(
    ContentEntityInterface $entity,
    string $field_name,
    string $property,
    string $value,
  ): string {
    return $this->enforceFillablePropertyMaxLength($entity, $field_name, $property, $value);
  }

}
