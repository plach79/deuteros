<?php

declare(strict_types=1);

namespace Deuteros\Tests\Integration;

use Deuteros\Double\EntityDoubleDefinition;
use Deuteros\Double\EntityDoubleDefinitionBuilder;
use Deuteros\Double\EntityDoubleFactory;
use Deuteros\Double\EntityDoubleFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Deuteros\Tests\Fixtures\SecondTestTrait;
use Deuteros\Tests\Fixtures\TestBundleTrait;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use PHPUnit\Framework\TestCase;

/**
 * Base test class for entity double factory integration tests.
 *
 * Contains shared tests that work identically across PHPUnit and Prophecy
 * factory implementations.
 */
abstract class EntityDoubleFactoryTestBase extends TestCase {

  /**
   * The factory under test.
   */
  protected EntityDoubleFactoryInterface $factory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->factory = EntityDoubleFactory::fromTest($this);
    $this->assertInstanceOf($this->getClassName(), $this->factory);
  }

  /**
   * Creates the factory instance for this test.
   *
   * @return class-string<object>
   *   The name of the class of the factory to test.
   */
  abstract protected function getClassName(): string;

  /**
   * Tests creating an entity double with only "entity_type" specified.
   */
  public function testMinimalEntityDouble(): void {
    $entity = $this->factory->create(
      new EntityDoubleDefinition('node')
    );

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertInstanceOf(EntityInterface::class, $entity);
    $this->assertSame('node', $entity->getEntityTypeId());
    $this->assertSame('node', $entity->bundle());
  }

  /**
   * Tests that guardrail methods like save() throw LogicException.
   */
  public function testUnsupportedMethodThrows(): void {
    $entity = $this->factory->create(
      new EntityDoubleDefinition(entityType: 'node', bundle: 'article')
    );

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Method 'save' is not supported");
    $this->expectExceptionMessage('Kernel test');

    $entity->save();
  }

  /**
   * Tests that mutable entities allow field value updates via set().
   */
  public function testMutableEntityFieldSet(): void {
    $entity = $this->factory->createMutable(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_status', 'draft')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Initial value.
    $this->assertSame('draft', $entity->get('field_status')->value);

    // Update the field.
    $entity->set('field_status', 'published');

    // New value should be accessible.
    $this->assertSame('published', $entity->get('field_status')->value);
  }

  /**
   * Tests that ::set() returns the entity for method chaining.
   */
  public function testMutableEntitySetReturnsEntity(): void {
    $entity = $this->factory->createMutable(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_status', 'draft')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $result = $entity->set('field_status', 'published');

    // Should return the entity for chaining.
    $this->assertSame($entity, $result);
  }

  /**
   * Tests accessing entity reference field "target_id" property.
   */
  public function testEntityReferenceFieldWithTargetId(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['target_id' => 42])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // @phpstan-ignore method.impossibleType
    $this->assertSame(42, $entity->get('field_author')->target_id);
    $first = $entity->get('field_author')->first();
    assert($first !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame(42, $first->target_id);
  }

  /**
   * Tests implementing multiple interfaces with method overrides.
   */
  public function testInterfaceComposition(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->interface(FieldableEntityInterface::class)
        ->interface(EntityChangedInterface::class)
        ->method('getChangedTime', fn() => 1704067200)
        ->method('setChangedTime', fn() => throw new \LogicException('Read-only'))
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertInstanceOf(EntityChangedInterface::class, $entity);
    $this->assertSame(1704067200, $entity->getChangedTime());
  }

  /**
   * Tests fromInterface() creates a working double for ContentEntityInterface.
   */
  public function testFromInterfaceWithContentEntity(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', ContentEntityInterface::class)
        ->bundle('article')
        ->id(42)
        ->field('field_test', 'value')
        ->build()
    );
    assert($entity instanceof ContentEntityInterface);

    $this->assertSame('node', $entity->getEntityTypeId());
    $this->assertSame('article', $entity->bundle());
    $this->assertSame(42, $entity->id());
    $this->assertSame('value', $entity->get('field_test')->value);
  }

  /**
   * Tests fromInterface() creates a working double for ConfigEntityInterface.
   */
  public function testFromInterfaceWithConfigEntity(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('view', ConfigEntityInterface::class)
        ->id('frontpage')
        ->label('Frontpage View')
        ->method('status', fn() => TRUE)
        ->build()
    );
    assert($entity instanceof ConfigEntityInterface);

    $this->assertSame('view', $entity->getEntityTypeId());
    $this->assertSame('frontpage', $entity->id());
    $this->assertSame('Frontpage View', $entity->label());
    $this->assertTrue($entity->status());
  }

  /**
   * Tests lenient mode allows unsupported methods to return null.
   */
  public function testLenientModeAllowsUnsupportedMethods(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', ContentEntityInterface::class)
        ->bundle('article')
        ->lenient()
        ->build()
    );

    // In lenient mode, save() should return null instead of throwing.
    // PHPStan: save() returns int per PHPDoc, but in lenient mode we return
    // null. This is intentional - we're testing our mock behavior.
    $result = $entity->save();
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($result);
  }

  /**
   * Tests that without lenient mode, unsupported methods still throw.
   */
  public function testNonLenientModeThrowsForUnsupportedMethods(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', ContentEntityInterface::class)
        ->bundle('article')
        ->build()
    );

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Method 'save' is not supported");

    $entity->save();
  }

  /**
   * Tests entity reference field with single entity via shorthand syntax.
   */
  public function testEntityReferenceShorthand(): void {
    $user = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(42)
        ->field('name', 'admin')
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', $user)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Access entity via shorthand.
    $this->assertSame($user, $entity->get('field_author')->entity);

    // Access via first().
    $first = $entity->get('field_author')->first();
    assert($first !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame($user, $first->entity);

    // target_id should be auto-populated.
    // @phpstan-ignore method.impossibleType
    $this->assertSame(42, $entity->get('field_author')->target_id);
  }

  /**
   * Tests entity reference field with explicit array format.
   */
  public function testEntityReferenceExplicitFormat(): void {
    $user = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(42)
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['entity' => $user])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame($user, $entity->get('field_author')->entity);
    // @phpstan-ignore method.impossibleType
    $this->assertSame(42, $entity->get('field_author')->target_id);
  }

  /**
   * Tests entity reference with NULL entity ID (new unsaved entity).
   */
  public function testEntityReferenceWithNullId(): void {
    $user = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(NULL)
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', $user)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame($user, $entity->get('field_author')->entity);
    // @phpstan-ignore method.impossibleType
    $this->assertNull($entity->get('field_author')->target_id);
  }

  /**
   * Tests entity reference field implements the expected interface.
   */
  public function testEntityReferenceFieldImplementsInterface(): void {
    $user = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(42)
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', $user)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $entity->get('field_author')
    );
  }

  /**
   * Tests non-entity-reference field does not implement the interface.
   */
  public function testNonEntityReferenceFieldDoesNotImplementInterface(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_title', 'Test Title')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertNotInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $entity->get('field_title')
    );
  }

  /**
   * Tests entity reference target_id mismatch throws exception.
   */
  public function testEntityReferenceTargetIdMismatchThrows(): void {
    $user = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(42)
        ->build()
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Entity reference target_id mismatch");

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['entity' => $user, 'target_id' => 999])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // The exception is thrown when the field value is normalized.
    // Accessing a property triggers the normalization via the resolver.
    $entity->get('field_author')->entity;
  }

  /**
   * Tests empty entity reference field with `['entity' => NULL]`.
   */
  public function testEmptyEntityReferenceWithNullEntity(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['entity' => NULL])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Should implement EntityReferenceFieldItemListInterface.
    $fieldList = $entity->get('field_author');
    $this->assertInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $fieldList
    );

    // Should be empty.
    $this->assertTrue($fieldList->isEmpty());

    // referencedEntities() should return empty array.
    $this->assertSame([], $fieldList->referencedEntities());
  }

  /**
   * Tests multi-value entity reference with some NULL entities.
   */
  public function testMultiValueEntityReferenceWithSomeNullEntities(): void {
    $tag1 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(1)
        ->build()
    );
    $tag2 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(2)
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [
          $tag1,
          ['entity' => NULL],
          $tag2,
        ])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $fieldList = $entity->get('field_tags');
    assert($fieldList instanceof EntityReferenceFieldItemListInterface);

    // referencedEntities() should return only valid entities.
    $entities = $fieldList->referencedEntities();
    $this->assertCount(2, $entities);
    $this->assertSame($tag1, $entities[0]);
    $this->assertSame($tag2, $entities[1]);
  }

  /**
   * Tests target_id-only entity reference field implements interface.
   */
  public function testTargetIdOnlyEntityReferenceImplementsInterface(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['target_id' => 42])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Should implement EntityReferenceFieldItemListInterface.
    $fieldList = $entity->get('field_author');
    $this->assertInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $fieldList
    );

    // target_id should be accessible.
    // @phpstan-ignore method.impossibleType
    $this->assertSame(42, $fieldList->target_id);
  }

  /**
   * Tests target_id-only entity reference throws on ::referencedEntities.
   */
  public function testTargetIdOnlyEntityReferenceThrowsOnReferencedEntities(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_author', ['target_id' => 42])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $fieldList = $entity->get('field_author');
    assert($fieldList instanceof EntityReferenceFieldItemListInterface);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot call referencedEntities() on field 'field_author'");
    $this->expectExceptionMessage('target_id values without corresponding entity doubles');

    // @phpstan-ignore method.resultUnused
    $fieldList->referencedEntities();
  }

  /**
   * Tests multi-value entity reference with target_id-only items throws.
   */
  public function testMultiValueWithTargetIdOnlyThrowsOnReferencedEntities(): void {
    $tag1 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(1)
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [
          $tag1,
          ['target_id' => 2],
        ])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $fieldList = $entity->get('field_tags');
    assert($fieldList instanceof EntityReferenceFieldItemListInterface);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot call referencedEntities() on field 'field_tags'");

    // @phpstan-ignore method.resultUnused
    $fieldList->referencedEntities();
  }

  /**
   * Tests using the traits() method to add multiple traits at once.
   */
  public function testTraitsMethodAddsMultipleTraits(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(7)
        ->traits([TestBundleTrait::class, SecondTestTrait::class])
        ->build()
    );

    // @phpstan-ignore method.notFound
    $this->assertSame(14, $entity->getEntityIdTimesTwo());
    // @phpstan-ignore method.notFound
    $this->assertSame('from_second_trait', $entity->getSecondTraitValue());
  }

  /**
   * Tests that trait methods work with mutable entity doubles.
   */
  public function testTraitWithMutableDouble(): void {
    $entity = $this->factory->createMutable(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_test', 'initial')
        ->trait(TestBundleTrait::class)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Trait method reads initial value.
    // @phpstan-ignore method.notFound
    $this->assertSame('initial', $entity->getTestFieldValue());

    // Mutate the field.
    $entity->set('field_test', 'updated');

    // Trait method sees the updated value.
    // @phpstan-ignore method.notFound
    $this->assertSame('updated', $entity->getTestFieldValue());
  }

  /**
   * Tests toUrl basic functionality.
   */
  public function testToUrlBasicFunctionality(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->id(1)
        ->url('/node/1')
        ->build()
    );

    $url = $entity->toUrl();
    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('/node/1', $url->toString());
    $this->assertSame('/node/1', $url->toString(FALSE));
  }

  /**
   * Tests toUrl with GeneratedUrl.
   */
  public function testToUrlWithGeneratedUrl(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->id(42)
        ->url('/node/42')
        ->build()
    );

    $url = $entity->toUrl();
    $generatedUrl = $url->toString(TRUE);

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertInstanceOf(GeneratedUrl::class, $generatedUrl);
    $this->assertSame('/node/42', $generatedUrl->getGeneratedUrl());
  }

}
