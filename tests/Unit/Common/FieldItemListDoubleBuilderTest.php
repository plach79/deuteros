<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Common;

use Drupal\Core\Entity\EntityInterface;
use Deuteros\Common\FieldDoubleDefinition;
use Deuteros\Common\FieldItemListDoubleBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the FieldItemListDoubleBuilder resolver factory.
 *
 * These tests verify that the builder creates correct resolvers for field
 * item list methods (first, isEmpty, getValue, get, etc.) without requiring
 * full factory integration.
 */
#[CoversClass(FieldItemListDoubleBuilder::class)]
#[Group('deuteros')]
class FieldItemListDoubleBuilderTest extends TestCase {

  /**
   * Tests ::first resolver returns null for empty field.
   */
  public function testFirstResolverEmpty(): void {
    $definition = new FieldDoubleDefinition([]);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $builder->setFieldItemFactory($this->createMockFieldItemFactory());
    $resolvers = $builder->getResolvers();

    $this->assertNull($resolvers['first']([]));
  }

  /**
   * Tests ::first resolver returns first item for non-empty field.
   */
  public function testFirstResolverWithValue(): void {
    $definition = new FieldDoubleDefinition('test value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $mockItem = new \stdClass();
    $builder->setFieldItemFactory(fn() => $mockItem);
    $resolvers = $builder->getResolvers();

    $this->assertSame($mockItem, $resolvers['first']([]));
  }

  /**
   * Tests ::isEmpty resolver returns true for null values.
   */
  public function testIsEmptyResolverTrueForNull(): void {
    $definition = new FieldDoubleDefinition(NULL);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $this->assertTrue($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::isEmpty resolver returns true for empty array.
   */
  public function testIsEmptyResolverTrueForEmptyArray(): void {
    $definition = new FieldDoubleDefinition([]);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $this->assertTrue($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::isEmpty resolver returns false for non-empty values.
   */
  public function testIsEmptyResolverFalse(): void {
    $definition = new FieldDoubleDefinition('value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $this->assertFalse($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::getValue resolver normalizes scalar to property array.
   */
  public function testGetValueResolverNormalizesScalar(): void {
    $definition = new FieldDoubleDefinition('scalar value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $this->assertSame([['value' => 'scalar value']], $resolvers['getValue']([]));
  }

  /**
   * Tests ::getValue resolver normalizes null to empty array.
   */
  public function testGetValueResolverNormalizesNull(): void {
    $definition = new FieldDoubleDefinition(NULL);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $this->assertSame([], $resolvers['getValue']([]));
  }

  /**
   * Tests ::getValue resolver preserves indexed arrays.
   */
  public function testGetValueResolverPreservesIndexedArray(): void {
    $values = [
      ['target_id' => 1],
      ['target_id' => 2],
      ['target_id' => 3],
    ];
    $definition = new FieldDoubleDefinition($values);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_tags');
    $resolvers = $builder->getResolvers();

    $this->assertSame($values, $resolvers['getValue']([]));
  }

  /**
   * Tests ::get resolver returns item at valid delta.
   */
  public function testGetResolverValidDelta(): void {
    $values = [
      ['target_id' => 1],
      ['target_id' => 2],
    ];
    $definition = new FieldDoubleDefinition($values);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_tags');

    $items = [];
    $builder->setFieldItemFactory(function (int $delta) use (&$items) {
      $item = new \stdClass();
      $item->delta = $delta;
      $items[$delta] = $item;
      return $item;
    });

    $resolvers = $builder->getResolvers();

    $item0 = $resolvers['get']([], 0);
    $item1 = $resolvers['get']([], 1);
    assert($item0 !== NULL && $item1 !== NULL);

    // @phpstan-ignore property.nonObject
    $this->assertSame(0, $item0->delta);
    // @phpstan-ignore property.nonObject
    $this->assertSame(1, $item1->delta);
  }

  /**
   * Tests ::get resolver returns null for out-of-range delta.
   */
  public function testGetResolverInvalidDelta(): void {
    $definition = new FieldDoubleDefinition(['value']);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $builder->setFieldItemFactory(fn() => new \stdClass());
    $resolvers = $builder->getResolvers();

    $this->assertNull($resolvers['get']([], 99));
  }

  /**
   * Tests ::__get resolver proxies to first item's __get.
   */
  public function testMagicGetProxiesToFirst(): void {
    $definition = new FieldDoubleDefinition('test value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');

    // Create a mock that implements __get.
    $mockItem = new class () {

      /**
       * Magic get.
       */
      public function __get(string $property): ?string {
        return $property === 'value' ? 'resolved value' : NULL;
      }

    };
    $builder->setFieldItemFactory(fn() => $mockItem);
    $resolvers = $builder->getResolvers();

    $this->assertSame('resolved value', $resolvers['__get']([], 'value'));
  }

  /**
   * Tests ::__get resolver returns null when field is empty.
   */
  public function testMagicGetReturnsNullForEmpty(): void {
    $definition = new FieldDoubleDefinition([]);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $builder->setFieldItemFactory(fn() => new \stdClass());
    $resolvers = $builder->getResolvers();

    $this->assertNull($resolvers['__get']([], 'value'));
  }

  /**
   * Tests ::setValue resolver throws on immutable field list.
   */
  public function testSetValueThrowsOnImmutable(): void {
    $definition = new FieldDoubleDefinition('value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test', FALSE);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify field 'field_test' on immutable");
    $this->expectExceptionMessage('createMutableEntityDouble()');

    $resolvers['setValue']([], 'new value');
  }

  /**
   * Tests ::setValue resolver updates mutable state.
   */
  public function testSetValueUpdatesMutableState(): void {
    $definition = new FieldDoubleDefinition('initial');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test', TRUE);

    $updatedField = NULL;
    $updatedValue = NULL;
    $builder->setMutableStateUpdater(function ($field, $value) use (&$updatedField, &$updatedValue) {
      $updatedField = $field;
      $updatedValue = $value;
    });

    $resolvers = $builder->getResolvers();
    $resolvers['setValue']([], 'new value');

    $this->assertSame('field_test', $updatedField);
    $this->assertSame('new value', $updatedValue);
  }

  /**
   * Tests ::__set resolver for "value" property.
   */
  public function testMagicSetValueProperty(): void {
    $definition = new FieldDoubleDefinition('initial');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test', TRUE);

    $updatedValue = NULL;
    $builder->setMutableStateUpdater(function ($field, $value) use (&$updatedValue) {
      $updatedValue = $value;
    });

    $resolvers = $builder->getResolvers();
    $resolvers['__set']([], 'value', 'new value');

    $this->assertSame('new value', $updatedValue);
  }

  /**
   * Tests ::__set resolver throws for non-value properties.
   */
  public function testMagicSetThrowsForNonValueProperty(): void {
    $definition = new FieldDoubleDefinition('initial');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test', TRUE);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Setting property 'target_id' on field item list is not supported");

    $resolvers['__set']([], 'target_id', 42);
  }

  /**
   * Tests callable field value resolution with context.
   */
  public function testCallableValueResolution(): void {
    $definition = new FieldDoubleDefinition(
      fn(array $context) => $context['computed_value']
    );
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    $values = $resolvers['getValue'](['computed_value' => 'dynamic']);
    $this->assertSame([['value' => 'dynamic']], $values);
  }

  /**
   * Tests that callable value is resolved only once (cached).
   */
  public function testValueCaching(): void {
    $callCount = 0;
    $definition = new FieldDoubleDefinition(
      function () use (&$callCount) {
        $callCount++;
        return 'resolved';
      }
    );
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');
    $resolvers = $builder->getResolvers();

    // Call getValue multiple times.
    $resolvers['getValue']([]);
    $resolvers['getValue']([]);
    $resolvers['getValue']([]);

    $this->assertSame(1, $callCount, 'Callable should only be invoked once');
  }

  /**
   * Tests field item caching.
   */
  public function testFieldItemCaching(): void {
    $values = [
      ['target_id' => 1],
      ['target_id' => 2],
    ];
    $definition = new FieldDoubleDefinition($values);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_tags');

    $callCount = 0;
    $builder->setFieldItemFactory(function () use (&$callCount) {
      $callCount++;
      return new \stdClass();
    });

    $resolvers = $builder->getResolvers();

    // Access same delta multiple times.
    $resolvers['get']([], 0);
    $resolvers['get']([], 0);
    $resolvers['first']([]);

    $this->assertSame(1, $callCount, 'Field item factory should only be called once per delta');
  }

  /**
   * Tests ::referencedEntities resolver with entity references.
   */
  public function testReferencedEntitiesResolver(): void {
    $entity1 = $this->createMock(EntityInterface::class);
    $entity1->method('id')->willReturn(1);
    $entity2 = $this->createMock(EntityInterface::class);
    $entity2->method('id')->willReturn(2);

    $definition = new FieldDoubleDefinition([$entity1, $entity2]);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_refs');
    $resolvers = $builder->getResolvers();

    $entities = $resolvers['referencedEntities']([]);
    assert(is_array($entities));

    $this->assertCount(2, $entities);
    $this->assertSame($entity1, $entities[0]);
    $this->assertSame($entity2, $entities[1]);
  }

  /**
   * Tests ::referencedEntities resolver returns empty for non-entity field.
   */
  public function testReferencedEntitiesResolverEmpty(): void {
    $definition = new FieldDoubleDefinition('plain value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_text');
    $resolvers = $builder->getResolvers();

    $entities = $resolvers['referencedEntities']([]);

    $this->assertSame([], $entities);
  }

  /**
   * Tests ::hasEntityReferences returns true after resolving entity refs.
   */
  public function testHasEntityReferencesTrue(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('id')->willReturn(1);

    $definition = new FieldDoubleDefinition($entity);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_ref');
    $builder->setFieldItemFactory(fn() => new \stdClass());
    $resolvers = $builder->getResolvers();

    // Trigger value resolution.
    $resolvers['first']([]);

    $this->assertTrue($builder->hasEntityReferences());
  }

  /**
   * Tests ::hasEntityReferences returns false for non-entity fields.
   */
  public function testHasEntityReferencesFalse(): void {
    $definition = new FieldDoubleDefinition('plain value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_text');
    $builder->setFieldItemFactory(fn() => new \stdClass());
    $resolvers = $builder->getResolvers();

    // Trigger value resolution.
    $resolvers['first']([]);

    $this->assertFalse($builder->hasEntityReferences());
  }

  /**
   * Tests ::getFieldName returns the field name.
   */
  public function testGetFieldName(): void {
    $definition = new FieldDoubleDefinition('value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_custom');

    $this->assertSame('field_custom', $builder->getFieldName());
  }

  /**
   * Tests ::getFieldDefinition returns the field definition.
   */
  public function testGetFieldDefinition(): void {
    $definition = new FieldDoubleDefinition('value');
    $builder = new FieldItemListDoubleBuilder($definition, 'field_test');

    $this->assertSame($definition, $builder->getFieldDefinition());
  }

  /**
   * Tests ::getValue resolver with single item having multiple properties.
   */
  public function testGetValueResolverSingleItemMultipleProperties(): void {
    $value = ['uri' => 'https://example.com', 'title' => 'Example'];
    $definition = new FieldDoubleDefinition($value);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_link');
    $resolvers = $builder->getResolvers();

    $this->assertSame(
      [['uri' => 'https://example.com', 'title' => 'Example']],
      $resolvers['getValue']([])
    );
  }

  /**
   * Tests ::getValue resolver with multiple items having multiple properties.
   */
  public function testGetValueResolverMultipleItemsMultipleProperties(): void {
    $values = [
      ['uri' => 'https://example.com', 'title' => 'Example'],
      ['uri' => 'https://drupal.org', 'title' => 'Drupal'],
    ];
    $definition = new FieldDoubleDefinition($values);
    $builder = new FieldItemListDoubleBuilder($definition, 'field_links');
    $resolvers = $builder->getResolvers();

    $this->assertSame($values, $resolvers['getValue']([]));
  }

  /**
   * Creates a mock field item factory that returns stdClass objects.
   *
   * @return callable
   *   The factory callable.
   */
  private function createMockFieldItemFactory(): callable {
    return fn() => new \stdClass();
  }

}
