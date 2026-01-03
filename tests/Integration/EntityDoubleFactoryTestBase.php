<?php

declare(strict_types=1);

namespace Deuteros\Tests\Integration;

use Deuteros\Common\EntityDoubleDefinition;
use Deuteros\Common\EntityDoubleDefinitionBuilder;
use Deuteros\Common\EntityDoubleFactory;
use Deuteros\Common\EntityDoubleFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
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
   * Tests entity metadata accessors (id, uuid, label, bundle).
   */
  public function testEntityWithMetadata(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(42)
        ->uuid('test-uuid-123')
        ->label('Test Article')
        ->build()
    );

    $this->assertSame('node', $entity->getEntityTypeId());
    $this->assertSame('article', $entity->bundle());
    $this->assertSame(42, $entity->id());
    $this->assertSame('test-uuid-123', $entity->uuid());
    $this->assertSame('Test Article', $entity->label());
  }

  /**
   * Tests accessing scalar field values via get() method.
   */
  public function testScalarFieldAccess(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_title', 'Test Title')
        ->field('field_count', 42)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame('Test Title', $entity->get('field_title')->value);
    $this->assertSame(42, $entity->get('field_count')->value);
  }

  /**
   * Tests that callback field values receive context and resolve correctly.
   */
  public function testCallbackFieldResolution(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_dynamic', fn(array $context) => $context['dynamic_value'])
        ->build(),
      ['dynamic_value' => 'Resolved from context'],
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame('Resolved from context', $entity->get('field_dynamic')->value);
  }

  /**
   * Tests context propagation to metadata and field callbacks.
   */
  public function testContextPropagation(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(fn(array $context) => $context['computed_id'])
        // @phpstan-ignore-next-line
        ->label(fn(array $context) => "Label: {$context['title']}")
        // @phpstan-ignore-next-line
        ->field('field_computed', fn(array $context) => $context['title'] . ' Field')
        ->build(),
      [
        'computed_id' => 100,
        'title' => 'Dynamic',
      ],
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame(100, $entity->id());
    $this->assertSame('Label: Dynamic', $entity->label());
    $this->assertSame('Dynamic Field', $entity->get('field_computed')->value);
  }

  /**
   * Tests accessing multi-value fields via ::first(), ::get($i), and shorthand.
   */
  public function testMultiValueFieldAccess(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [
          ['target_id' => 1],
          ['target_id' => 2],
          ['target_id' => 3],
        ])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Access via first().
    $first = $entity->get('field_tags')->first();
    assert($first !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame(1, $first->target_id);

    // Access via get(delta).
    $item0 = $entity->get('field_tags')->get(0);
    $item1 = $entity->get('field_tags')->get(1);
    $item2 = $entity->get('field_tags')->get(2);
    assert($item0 !== NULL && $item1 !== NULL && $item2 !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame(1, $item0->target_id);
    // @phpstan-ignore property.notFound
    $this->assertSame(2, $item1->target_id);
    // @phpstan-ignore property.notFound
    $this->assertSame(3, $item2->target_id);

    // Access via shorthand.
    // @phpstan-ignore method.impossibleType
    $this->assertSame(1, $entity->get('field_tags')->target_id);
  }

  /**
   * Tests chained field access: entity -> field list -> item -> property.
   */
  public function testNestedFieldAccess(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_text', 'Plain text value')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Chain: entity -> field list -> first item -> value property.
    $this->assertSame('Plain text value', $entity->get('field_text')->value);
    $first = $entity->get('field_text')->first();
    assert($first !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame('Plain text value', $first->value);
  }

  /**
   * Tests ::hasField() returns correct boolean for defined fields.
   */
  public function testHasField(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_existing', 'value')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertTrue($entity->hasField('field_existing'));
    $this->assertFalse($entity->hasField('field_nonexistent'));
  }

  /**
   * Tests that "method_overrides" take precedence over default resolvers.
   */
  public function testMethodOverridesPrecedence(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(1)
        ->method('id', fn() => 999)
        ->build()
    );

    // The override should take precedence.
    $this->assertSame(999, $entity->id());
  }

  /**
   * Tests that method override callbacks receive context array.
   *
   * This is an implementation-agnostic version that doesn't require
   * EntityChangedInterface, since that interface behaves differently
   * in PHPUnit vs Prophecy.
   */
  public function testMethodOverridesReceiveContext(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->method('id', fn(array $context) => $context['computed_id'])
        ->build(),
      ['computed_id' => 999],
    );

    $this->assertSame(999, $entity->id());
  }

  /**
   * Tests that accessing undefined fields throws "InvalidArgumentException".
   */
  public function testUndefinedFieldThrows(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_defined', 'value')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Field 'field_undefined' is not defined");

    $entity->get('field_undefined');
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
   * Tests that immutable entities throw on ::set() attempts.
   */
  public function testImmutableEntityRejectsSet(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_status', 'draft')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify field 'field_status' on immutable entity double");
    $this->expectExceptionMessage('createMutableEntityDouble()');

    $entity->set('field_status', 'published');
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
   * Tests magic __get for field access via property syntax.
   */
  public function testMagicGetFieldAccess(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_title', 'Test Title')
        ->field('field_author', ['target_id' => 42])
        ->build()
    );

    // Magic property access should work.
    // @phpstan-ignore property.notFound, property.nonObject
    $this->assertSame('Test Title', $entity->field_title->value);
    // @phpstan-ignore property.notFound, property.nonObject
    $this->assertSame(42, $entity->field_author->target_id);
  }

  /**
   * Tests magic __set for mutable entities via property syntax.
   */
  public function testMagicSetOnMutableEntity(): void {
    $entity = $this->factory->createMutable(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_status', 'draft')
        ->build()
    );

    // Initial value via magic get.
    // @phpstan-ignore property.notFound, property.nonObject
    $this->assertSame('draft', $entity->field_status->value);

    // Update via magic set.
    // @phpstan-ignore property.notFound
    $entity->field_status = 'published';

    // New value should be accessible.
    // @phpstan-ignore property.nonObject
    $this->assertSame('published', $entity->field_status->value);
  }

  /**
   * Tests magic __set throws on immutable entities.
   */
  public function testMagicSetOnImmutableEntityThrows(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_status', 'draft')
        ->build()
    );

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify field 'field_status' on immutable entity double");

    // @phpstan-ignore property.notFound
    $entity->field_status = 'published';
  }

  /**
   * Tests magic __get for undefined field throws.
   */
  public function testMagicGetUndefinedFieldThrows(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_defined', 'value')
        ->build()
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Field 'field_undefined' is not defined");

    // Access undefined field via magic property.
    // @phpstan-ignore property.notFound, expr.resultUnused
    $entity->field_undefined;
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
   * Tests ::referencedEntities() for multi-value entity reference fields.
   */
  public function testReferencedEntities(): void {
    $tag1 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(1)
        ->label('Tag 1')
        ->build()
    );
    $tag2 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(2)
        ->label('Tag 2')
        ->build()
    );
    $tag3 = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('taxonomy_term')
        ->id(3)
        ->label('Tag 3')
        ->build()
    );

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [$tag1, $tag2, $tag3])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $fieldList = $entity->get('field_tags');
    assert($fieldList instanceof EntityReferenceFieldItemListInterface);

    $entities = $fieldList->referencedEntities();
    $this->assertCount(3, $entities);
    $this->assertSame($tag1, $entities[0]);
    $this->assertSame($tag2, $entities[1]);
    $this->assertSame($tag3, $entities[2]);
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
   * Tests accessing individual items in multi-value entity reference field.
   */
  public function testMultiValueEntityReferenceItemAccess(): void {
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
        ->field('field_tags', [$tag1, $tag2])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Access via first().
    $first = $entity->get('field_tags')->first();
    assert($first !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame($tag1, $first->entity);
    // @phpstan-ignore property.notFound
    $this->assertSame(1, $first->target_id);

    // Access via get(delta).
    $item1 = $entity->get('field_tags')->get(1);
    assert($item1 !== NULL);
    // @phpstan-ignore property.notFound
    $this->assertSame($tag2, $item1->entity);
    // @phpstan-ignore property.notFound
    $this->assertSame(2, $item1->target_id);
  }

  /**
   * Tests empty field returns empty array from ::referencedEntities().
   */
  public function testEmptyEntityReferenceField(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // Empty field should not implement EntityReferenceFieldItemListInterface.
    // since no entity references were detected.
    $this->assertTrue($entity->get('field_tags')->isEmpty());
  }

  /**
   * Tests ::getValue returns property arrays for scalar fields.
   */
  public function testGetValueReturnsPropertyArrays(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_title', 'Test Title')
        ->field('field_count', 42)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // FieldItemList::getValue() should return array of property arrays.
    $this->assertSame(
      [['value' => 'Test Title']],
      $entity->get('field_title')->getValue()
    );
    $this->assertSame(
      [['value' => 42]],
      $entity->get('field_count')->getValue()
    );
  }

  /**
   * Tests field item ::getValue returns property array.
   */
  public function testFieldItemGetValueReturnsPropertyArray(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_title', 'Test Title')
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    // FieldItem::getValue() should return property array.
    $first = $entity->get('field_title')->first();
    assert($first !== NULL);
    $this->assertSame(['value' => 'Test Title'], $first->getValue());
  }

  /**
   * Tests ::getValue preserves existing property structure.
   */
  public function testGetValuePreservesExistingStructure(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_tags', [['target_id' => 1], ['target_id' => 2]])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame(
      [['target_id' => 1], ['target_id' => 2]],
      $entity->get('field_tags')->getValue()
    );
  }

  /**
   * Tests ::getValue with single item having multiple properties.
   */
  public function testGetValueSingleItemMultipleProperties(): void {
    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_link', ['uri' => 'https://example.com', 'title' => 'Example'])
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame(
      [['uri' => 'https://example.com', 'title' => 'Example']],
      $entity->get('field_link')->getValue()
    );

    // Also verify field item getValue().
    $first = $entity->get('field_link')->first();
    assert($first !== NULL);
    $this->assertSame(
      ['uri' => 'https://example.com', 'title' => 'Example'],
      $first->getValue()
    );
  }

  /**
   * Tests ::getValue with multiple items each having multiple properties.
   */
  public function testGetValueMultipleItemsMultipleProperties(): void {
    $values = [
      ['uri' => 'https://example.com', 'title' => 'Example'],
      ['uri' => 'https://drupal.org', 'title' => 'Drupal'],
    ];

    $entity = $this->factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->field('field_links', $values)
        ->build()
    );
    assert($entity instanceof FieldableEntityInterface);

    $this->assertSame($values, $entity->get('field_links')->getValue());

    // Verify individual items.
    $item0 = $entity->get('field_links')->get(0);
    $item1 = $entity->get('field_links')->get(1);
    assert($item0 !== NULL && $item1 !== NULL);

    $this->assertSame(
      ['uri' => 'https://example.com', 'title' => 'Example'],
      $item0->getValue()
    );
    $this->assertSame(
      ['uri' => 'https://drupal.org', 'title' => 'Drupal'],
      $item1->getValue()
    );
  }

}
