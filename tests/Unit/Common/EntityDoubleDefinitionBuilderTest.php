<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Common;

use Deuteros\Common\EntityDoubleDefinitionBuilder;
use Deuteros\Common\FieldDoubleDefinition;
use Deuteros\Tests\Fixtures\SecondTestTrait;
use Deuteros\Tests\Fixtures\TestBundleTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the EntityDoubleDefinitionBuilder.
 */
#[CoversClass(EntityDoubleDefinitionBuilder::class)]
#[Group('deuteros')]
class EntityDoubleDefinitionBuilderTest extends TestCase {

  /**
   * Tests building a minimal definition with only entity_type.
   */
  public function testMinimalBuilder(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')->build();

    $this->assertSame('node', $definition->entityType);
    $this->assertSame('node', $definition->bundle);
    $this->assertNull($definition->id);
    $this->assertNull($definition->uuid);
    $this->assertNull($definition->label);
    $this->assertSame([], $definition->fields);
    $this->assertSame([], $definition->interfaces);
    $this->assertSame([], $definition->methods);
    $this->assertSame([], $definition->context);
    $this->assertFalse($definition->mutable);
  }

  /**
   * Tests building a definition with all options.
   */
  public function testFullBuilder(): void {
    $callback = fn() => 1;

    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->bundle('article')
      ->id(42)
      ->uuid('test-uuid')
      ->label('Test Label')
      ->field('field_test', 'value')
      ->interface(EntityChangedInterface::class)
      ->method('getChangedTime', $callback)
      ->context('key', 'value')
      ->build();

    $this->assertSame('node', $definition->entityType);
    $this->assertSame('article', $definition->bundle);
    $this->assertSame(42, $definition->id);
    $this->assertSame('test-uuid', $definition->uuid);
    $this->assertSame('Test Label', $definition->label);
    $this->assertArrayHasKey('field_test', $definition->fields);
    $this->assertContains(FieldableEntityInterface::class, $definition->interfaces);
    $this->assertContains(EntityChangedInterface::class, $definition->interfaces);
    $this->assertSame($callback, $definition->methods['getChangedTime']);
    $this->assertSame(['key' => 'value'], $definition->context);
  }

  /**
   * Tests that adding a field auto-adds FieldableEntityInterface.
   */
  public function testFieldAutoAddsFieldableInterface(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->field('field_test', 'value')
      ->build();

    $this->assertContains(FieldableEntityInterface::class, $definition->interfaces);
  }

  /**
   * Tests that adding a field with FieldableEntityInterface already present.
   */
  public function testFieldWithExistingFieldableInterface(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->interface(FieldableEntityInterface::class)
      ->field('field_test', 'value')
      ->build();

    // Should not duplicate the interface.
    $count = array_count_values($definition->interfaces);
    $this->assertSame(1, $count[FieldableEntityInterface::class]);
  }

  /**
   * Tests that interface() deduplicates interfaces.
   */
  public function testInterfaceDeduplication(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->interface(EntityChangedInterface::class)
      ->interface(EntityChangedInterface::class)
      ->build();

    $count = array_count_values($definition->interfaces);
    $this->assertSame(1, $count[EntityChangedInterface::class]);
  }

  /**
   * Tests the interfaces() bulk method.
   */
  public function testInterfacesBulk(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->interfaces([
        FieldableEntityInterface::class,
        EntityChangedInterface::class,
      ])
      ->build();

    $this->assertContains(FieldableEntityInterface::class, $definition->interfaces);
    $this->assertContains(EntityChangedInterface::class, $definition->interfaces);
  }

  /**
   * Tests the fields() bulk method.
   */
  public function testFieldsBulk(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->fields([
        'field_one' => 'value1',
        'field_two' => 'value2',
      ])
      ->build();

    $this->assertArrayHasKey('field_one', $definition->fields);
    $this->assertArrayHasKey('field_two', $definition->fields);
    $this->assertSame('value1', $definition->fields['field_one']->getValue());
    $this->assertSame('value2', $definition->fields['field_two']->getValue());
  }

  /**
   * Tests the methodOverrides() bulk method.
   */
  public function testMethodOverridesBulk(): void {
    $callback1 = fn() => 1;
    $callback2 = fn() => 2;

    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->methods([
        'method1' => $callback1,
        'method2' => $callback2,
      ])
      ->build();

    $this->assertSame($callback1, $definition->methods['method1']);
    $this->assertSame($callback2, $definition->methods['method2']);
  }

  /**
   * Tests the withContext() bulk method.
   */
  public function testWithContextBulk(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->context('a', 1)
      ->withContext(['b' => 2, 'c' => 3])
      ->build();

    $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $definition->context);
  }

  /**
   * Tests creating a builder from an existing definition.
   */
  public function testFromExistingDefinition(): void {
    $original = EntityDoubleDefinitionBuilder::create('node')
      ->bundle('article')
      ->id(42)
      ->field('field_test', 'original')
      ->build();

    $modified = EntityDoubleDefinitionBuilder::from($original)
      ->label('New Label')
      ->field('field_test', 'modified')
      ->build();

    // Original should be unchanged.
    $this->assertNull($original->label);
    $this->assertSame('original', $original->fields['field_test']->getValue());

    // Modified should have new values but preserve unchanged ones.
    $this->assertSame('node', $modified->entityType);
    $this->assertSame('article', $modified->bundle);
    $this->assertSame(42, $modified->id);
    $this->assertSame('New Label', $modified->label);
    $this->assertSame('modified', $modified->fields['field_test']->getValue());
  }

  /**
   * Tests that field values are wrapped in FieldDoubleDefinition.
   */
  public function testFieldValuesWrappedInFieldDoubleDefinition(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->field('field_test', 'raw value')
      ->build();

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertInstanceOf(FieldDoubleDefinition::class, $definition->fields['field_test']);
    $this->assertSame('raw value', $definition->fields['field_test']->getValue());
  }

  /**
   * Tests that FieldDoubleDefinition values are preserved.
   */
  public function testFieldDoubleDefinitionPreserved(): void {
    $fieldDoubleDefinition = new FieldDoubleDefinition('wrapped value');

    $entityDoubleDefinition = EntityDoubleDefinitionBuilder::create('node')
      ->field('field_test', $fieldDoubleDefinition)
      ->build();

    $this->assertSame($fieldDoubleDefinition, $entityDoubleDefinition->fields['field_test']);
  }

  /**
   * Tests callable field values.
   */
  public function testCallableFieldValue(): void {
    $callback = fn(array $context) => $context['value'];

    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->field('field_dynamic', $callback)
      ->build();

    $this->assertTrue($definition->fields['field_dynamic']->isCallable());
  }

  /**
   * Tests callable metadata values.
   */
  public function testCallableMetadata(): void {
    $idCallback = fn(array $context) => $context['id'];
    $labelCallback = fn(array $context) => $context['label'];

    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->id($idCallback)
      ->label($labelCallback)
      ->build();

    $this->assertSame($idCallback, $definition->id);
    $this->assertSame($labelCallback, $definition->label);
  }

  /**
   * Tests fromInterface() auto-detects the interface hierarchy.
   */
  public function testFromInterfaceDetectsHierarchy(): void {
    $definition = EntityDoubleDefinitionBuilder::fromInterface(
      'node',
      ContentEntityInterface::class
    )->build();

    // ContentEntityInterface extends FieldableEntityInterface.
    $this->assertContains(ContentEntityInterface::class, $definition->interfaces);
    $this->assertContains(FieldableEntityInterface::class, $definition->interfaces);
    $this->assertContains(EntityInterface::class, $definition->interfaces);
  }

  /**
   * Tests fromInterface() keeps Traversable and IteratorAggregate.
   */
  public function testFromInterfaceKeepsTraversable(): void {
    $definition = EntityDoubleDefinitionBuilder::fromInterface(
      'node',
      ContentEntityInterface::class
    )->build();

    // ContentEntityInterface extends Traversable.
    $this->assertContains(\Traversable::class, $definition->interfaces);
  }

  /**
   * Tests fromInterface() throws for non-existent interface.
   */
  public function testFromInterfaceValidatesInterfaceExists(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Interface 'NonExistentInterface' does not exist.");

    // @phpstan-ignore argument.type
    EntityDoubleDefinitionBuilder::fromInterface('node', 'NonExistentInterface');
  }

  /**
   * Tests fromInterface() throws for non-EntityInterface.
   */
  public function testFromInterfaceRequiresEntityInterface(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("must extend EntityInterface");

    EntityDoubleDefinitionBuilder::fromInterface('node', \Traversable::class);
  }

  /**
   * Tests fromInterface() stores the primary interface.
   */
  public function testFromInterfaceStoresPrimaryInterface(): void {
    $definition = EntityDoubleDefinitionBuilder::fromInterface(
      'node',
      ContentEntityInterface::class
    )->build();

    $this->assertSame(ContentEntityInterface::class, $definition->primaryInterface);
  }

  /**
   * Tests the lenient() method.
   */
  public function testLenientFlag(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->lenient()
      ->build();

    $this->assertTrue($definition->lenient);
  }

  /**
   * Tests lenient(false) disables lenient mode.
   */
  public function testLenientFlagFalse(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->lenient()
      ->lenient(FALSE)
      ->build();

    $this->assertFalse($definition->lenient);
  }

  /**
   * Tests from() preserves primaryInterface and lenient.
   */
  public function testFromPreservesNewProperties(): void {
    $original = EntityDoubleDefinitionBuilder::fromInterface(
      'node',
      ContentEntityInterface::class
    )
      ->lenient()
      ->build();

    $modified = EntityDoubleDefinitionBuilder::from($original)
      ->label('Test')
      ->build();

    $this->assertSame(ContentEntityInterface::class, $modified->primaryInterface);
    $this->assertTrue($modified->lenient);
  }

  /**
   * Tests the trait() method adds a trait to the definition.
   */
  public function testTraitMethod(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->trait(TestBundleTrait::class)
      ->build();

    $this->assertContains(TestBundleTrait::class, $definition->traits);
  }

  /**
   * Tests that trait() deduplicates traits.
   */
  public function testTraitDeduplication(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->trait(TestBundleTrait::class)
      ->trait(TestBundleTrait::class)
      ->build();

    $count = array_count_values($definition->traits);
    $this->assertSame(1, $count[TestBundleTrait::class]);
  }

  /**
   * Tests that trait() throws for non-existent traits.
   */
  public function testInvalidTraitThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Trait 'NonExistentTrait' does not exist.");

    EntityDoubleDefinitionBuilder::create('node')
      // @phpstan-ignore argument.type
      ->trait('NonExistentTrait')
      ->build();
  }

  /**
   * Tests the traits() bulk method adds multiple traits.
   */
  public function testTraitsMethodAddsMultipleTraits(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->traits([TestBundleTrait::class, SecondTestTrait::class])
      ->build();

    $this->assertContains(TestBundleTrait::class, $definition->traits);
    $this->assertContains(SecondTestTrait::class, $definition->traits);
    $this->assertCount(2, $definition->traits);
  }

  /**
   * Tests from() preserves traits from an existing definition.
   */
  public function testFromPreservesTraits(): void {
    $original = EntityDoubleDefinitionBuilder::create('node')
      ->trait(TestBundleTrait::class)
      ->trait(SecondTestTrait::class)
      ->build();

    $modified = EntityDoubleDefinitionBuilder::from($original)
      ->label('New Label')
      ->build();

    $this->assertContains(TestBundleTrait::class, $modified->traits);
    $this->assertContains(SecondTestTrait::class, $modified->traits);
  }

  /**
   * Tests the url() method with a static value.
   */
  public function testUrlBuilder(): void {
    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->url('/node/1')
      ->build();

    $this->assertSame('/node/1', $definition->url);
  }

  /**
   * Tests the url() method with a callable.
   */
  public function testUrlBuilderWithCallable(): void {
    $callback = static function (array $context): string {
      $id = $context['id'] ?? '';
      assert(is_scalar($id));
      return '/node/' . $id;
    };

    $definition = EntityDoubleDefinitionBuilder::create('node')
      ->url($callback)
      ->build();

    $this->assertSame($callback, $definition->url);
  }

  /**
   * Tests from() preserves url from an existing definition.
   */
  public function testFromPreservesUrl(): void {
    $original = EntityDoubleDefinitionBuilder::create('node')
      ->url('/node/42')
      ->build();

    $modified = EntityDoubleDefinitionBuilder::from($original)
      ->label('New Label')
      ->build();

    $this->assertSame('/node/42', $modified->url);
  }

}
