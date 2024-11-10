<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_content_classification\Unit\Helper;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_content_classification\Helper\EntityHelper;

/**
 * Unit tests for the EntityHelper class.
 *
 * @coversDefaultClass \Drupal\ocha_content_classification\Helper\EntityHelper
 * @group ocha_content_classification
 */
class EntityHelperTest extends UnitTestCase {

  /**
   * Sets up the test environment.
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up a mock for the entity type bundle info service.
    $bundle_info_service = $this->createMock(EntityTypeBundleInfoInterface::class);

    // Define what the mock should return.
    $bundle_info_service->method('getBundleInfo')
      ->willReturn([
        'article' => ['label' => 'Article'],
      ]);

    // Create a new container.
    $container = new ContainerBuilder();

    // Add our mock entity type bundle info service.
    $container->set('entity_type.bundle.info', $bundle_info_service);

    // Register the mock service in the container.
    \Drupal::setContainer($container);
  }

  /**
   * Tests the getBundleLabelFromEntity() method.
   *
   * @covers ::getBundleLabelFromEntity
   */
  public function testGetBundleLabelFromEntity(): void {
    // Create a mock entity.
    $mock_entity = $this->createMock(EntityInterface::class);
    $mock_entity->method('getEntityTypeId')->willReturn('node');
    $mock_entity->method('bundle')->willReturn('article');

    // Call the method and assert the result.
    $result = EntityHelper::getBundleLabelFromEntity($mock_entity);
    $this->assertEquals('Article', $result);
  }

  /**
   * Tests the getBundleLabel() method.
   *
   * @covers ::getBundleLabel
   */
  public function testGetBundleLabel(): void {
    // Test valid bundle label.
    $result = EntityHelper::getBundleLabel('node', 'article');
    $this->assertEquals('Article', $result);

    // Test unknown bundle label (fallback to bundle name).
    $result = EntityHelper::getBundleLabel('node', 'unknown_bundle');
    $this->assertEquals('unknown_bundle', $result);
  }

}
