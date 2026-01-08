<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Double;

use Deuteros\Double\FieldItemDoubleBuilder;
use Drupal\Core\Entity\EntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the FieldItemDoubleBuilder resolver factory.
 *
 * These tests verify that the builder creates correct resolvers for field
 * item methods (__get, __set, getValue, setValue, isEmpty) without requiring
 * full factory integration.
 */
#[CoversClass(FieldItemDoubleBuilder::class)]
#[Group('deuteros')]
class FieldItemDoubleBuilderTest extends TestCase {

  /**
   * Tests ::__get resolver returns scalar value for "value" property.
   */
  public function testMagicGetValueForScalar(): void {
    $builder = new FieldItemDoubleBuilder('test value', 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertSame('test value', $resolvers['__get']([], 'value'));
  }

  /**
   * Tests ::__get resolver returns null for unknown property on scalar.
   */
  public function testMagicGetUnknownPropertyForScalar(): void {
    $builder = new FieldItemDoubleBuilder('test value', 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertNull($resolvers['__get']([], 'target_id'));
  }

  /**
   * Tests ::__get resolver looks up array properties.
   */
  public function testMagicGetPropertyForArray(): void {
    $value = ['target_id' => 42, 'target_type' => 'node'];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_ref');
    $resolvers = $builder->getResolvers();

    $this->assertSame(42, $resolvers['__get']([], 'target_id'));
    $this->assertSame('node', $resolvers['__get']([], 'target_type'));
  }

  /**
   * Tests ::__get resolver returns null for missing array property.
   */
  public function testMagicGetMissingArrayProperty(): void {
    $value = ['target_id' => 42];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_ref');
    $resolvers = $builder->getResolvers();

    $this->assertNull($resolvers['__get']([], 'nonexistent'));
  }

  /**
   * Tests ::__get resolver returns entity for "entity" property.
   */
  public function testMagicGetEntityProperty(): void {
    $entity = $this->createMock(EntityInterface::class);
    $value = ['entity' => $entity, 'target_id' => 42];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_author');
    $resolvers = $builder->getResolvers();

    $this->assertSame($entity, $resolvers['__get']([], 'entity'));
    $this->assertSame(42, $resolvers['__get']([], 'target_id'));
  }

  /**
   * Tests ::getValue resolver returns property array.
   */
  public function testGetValueResolver(): void {
    $builder = new FieldItemDoubleBuilder('raw value', 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertSame(['value' => 'raw value'], $resolvers['getValue']([]));
  }

  /**
   * Tests ::getValue resolver preserves existing property structure.
   */
  public function testGetValueResolverArray(): void {
    $value = ['target_id' => 42];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_ref');
    $resolvers = $builder->getResolvers();

    $this->assertSame($value, $resolvers['getValue']([]));
  }

  /**
   * Tests ::setValue resolver throws on immutable field item.
   */
  public function testSetValueThrowsOnImmutable(): void {
    $builder = new FieldItemDoubleBuilder('value', 0, 'field_text', FALSE);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify field 'field_text' item at delta 0 on immutable");
    $this->expectExceptionMessage('createMutable()');

    $resolvers['setValue']([], 'new value');
  }

  /**
   * Tests ::setValue resolver updates value on mutable field item.
   */
  public function testSetValueUpdatesMutable(): void {
    $builder = new FieldItemDoubleBuilder('initial', 0, 'field_text', TRUE);
    $resolvers = $builder->getResolvers();

    $resolvers['setValue']([], 'updated');

    // Verify through getValue (returns property array).
    $this->assertSame(['value' => 'updated'], $resolvers['getValue']([]));
    // Also verify through public getter (returns raw value).
    $this->assertSame('updated', $builder->getValue());
  }

  /**
   * Tests ::__set resolver for "value" property on mutable.
   */
  public function testMagicSetValueProperty(): void {
    $builder = new FieldItemDoubleBuilder('initial', 0, 'field_text', TRUE);
    $resolvers = $builder->getResolvers();

    $resolvers['__set']([], 'value', 'new value');

    $this->assertSame('new value', $resolvers['__get']([], 'value'));
  }

  /**
   * Tests ::__set resolver for array property on mutable.
   */
  public function testMagicSetArrayProperty(): void {
    $value = ['target_id' => 42];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_ref', TRUE);
    $resolvers = $builder->getResolvers();

    $resolvers['__set']([], 'target_id', 99);

    $this->assertSame(99, $resolvers['__get']([], 'target_id'));
  }

  /**
   * Tests ::__set resolver throws for non-value property on scalar.
   */
  public function testMagicSetThrowsForScalarNonValueProperty(): void {
    $builder = new FieldItemDoubleBuilder('scalar', 0, 'field_text', TRUE);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot set property 'target_id' on scalar field item");

    $resolvers['__set']([], 'target_id', 42);
  }

  /**
   * Tests ::__set resolver throws on immutable.
   */
  public function testMagicSetThrowsOnImmutable(): void {
    $builder = new FieldItemDoubleBuilder('value', 0, 'field_text', FALSE);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify property 'value' on immutable");
    $this->expectExceptionMessage('createMutable()');

    $resolvers['__set']([], 'value', 'new');
  }

  /**
   * Tests ::isEmpty resolver returns true for null.
   */
  public function testIsEmptyResolverNull(): void {
    $builder = new FieldItemDoubleBuilder(NULL, 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertTrue($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::isEmpty resolver returns true for empty string.
   */
  public function testIsEmptyResolverEmptyString(): void {
    $builder = new FieldItemDoubleBuilder('', 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertTrue($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::isEmpty resolver returns false for non-empty value.
   */
  public function testIsEmptyResolverFalse(): void {
    $builder = new FieldItemDoubleBuilder('value', 0, 'field_text');
    $resolvers = $builder->getResolvers();

    $this->assertFalse($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::isEmpty resolver returns false for zero.
   */
  public function testIsEmptyResolverZero(): void {
    $builder = new FieldItemDoubleBuilder(0, 0, 'field_number');
    $resolvers = $builder->getResolvers();

    $this->assertFalse($resolvers['isEmpty']([]));
  }

  /**
   * Tests ::getDelta returns the delta.
   */
  public function testGetDelta(): void {
    $builder = new FieldItemDoubleBuilder('value', 5, 'field_test');

    $this->assertSame(5, $builder->getDelta());
  }

  /**
   * Tests ::getValue returns the current value.
   */
  public function testGetValue(): void {
    $builder = new FieldItemDoubleBuilder('test value', 0, 'field_test');

    $this->assertSame('test value', $builder->getValue());
  }

  /**
   * Tests ::getValue resolver preserves multiple properties.
   */
  public function testGetValueResolverMultipleProperties(): void {
    $value = ['uri' => 'https://example.com', 'title' => 'Example'];
    $builder = new FieldItemDoubleBuilder($value, 0, 'field_link');
    $resolvers = $builder->getResolvers();

    $this->assertSame($value, $resolvers['getValue']([]));
  }

}
