<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Common;

use Deuteros\Common\EntityDoubleBuilder;
use Deuteros\Common\EntityDoubleDefinition;
use Deuteros\Common\FieldDoubleDefinition;
use Deuteros\Common\MutableStateContainer;
use Drupal\Core\Entity\FieldableEntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the EntityDoubleBuilder resolver factory.
 *
 * These tests verify that the builder creates correct resolvers for entity
 * methods (id, uuid, label, bundle, etc.) without requiring full factory
 * integration.
 */
#[CoversClass(EntityDoubleBuilder::class)]
#[Group('deuteros')]
class EntityDoubleBuilderTest extends TestCase {

  /**
   * Tests ::id resolver with a static value.
   */
  public function testIdResolverWithStaticValue(): void {
    $definition = new EntityDoubleDefinition(entityType: 'node', id: 42);
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame(42, $resolvers['id']([]));
  }

  /**
   * Tests ::id resolver with a callback receives context.
   */
  public function testIdResolverWithCallback(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      id: fn(array $context) => $context['computed_id'],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame(99, $resolvers['id'](['computed_id' => 99]));
  }

  /**
   * Tests ::uuid resolver with a static value.
   */
  public function testUuidResolverWithStaticValue(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      uuid: 'test-uuid-123',
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('test-uuid-123', $resolvers['uuid']([]));
  }

  /**
   * Tests ::uuid resolver with a callback receives context.
   */
  public function testUuidResolverWithCallback(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      uuid: fn(array $context) => $context['uuid'],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('dynamic-uuid', $resolvers['uuid'](['uuid' => 'dynamic-uuid']));
  }

  /**
   * Tests ::label resolver with a static value.
   */
  public function testLabelResolverWithStaticValue(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      label: 'Test Label',
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('Test Label', $resolvers['label']([]));
  }

  /**
   * Tests ::label resolver with a callback receives context.
   */
  public function testLabelResolverWithCallback(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      // @phpstan-ignore-next-line
      label: fn(array $context) => "Label: {$context['title']}",
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('Label: Dynamic', $resolvers['label'](['title' => 'Dynamic']));
  }

  /**
   * Tests ::bundle resolver returns the definition bundle.
   */
  public function testBundleResolver(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      bundle: 'article',
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('article', $resolvers['bundle']([]));
  }

  /**
   * Tests ::getEntityTypeId resolver returns the definition entityType.
   */
  public function testEntityTypeIdResolver(): void {
    $definition = new EntityDoubleDefinition(entityType: 'taxonomy_term');
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertSame('taxonomy_term', $resolvers['getEntityTypeId']([]));
  }

  /**
   * Tests ::hasField resolver returns true for defined fields.
   */
  public function testHasFieldResolverTrue(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertTrue($resolvers['hasField']([], 'field_test'));
  }

  /**
   * Tests ::hasField resolver returns false for undefined fields.
   */
  public function testHasFieldResolverFalse(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->assertFalse($resolvers['hasField']([], 'nonexistent'));
  }

  /**
   * Tests ::get resolver throws without a field list factory.
   */
  public function testGetResolverThrowsWithoutFactory(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Field list factory not set');

    $resolvers['get']([], 'field_test');
  }

  /**
   * Tests ::get resolver caches field list instances.
   */
  public function testGetResolverCachesFieldList(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
    );
    $builder = new EntityDoubleBuilder($definition);

    $callCount = 0;
    $mockFieldList = new \stdClass();
    $builder->setFieldListFactory(function () use (&$callCount, $mockFieldList) {
      $callCount++;
      return $mockFieldList;
    });

    $resolvers = $builder->getResolvers();

    $first = $resolvers['get']([], 'field_test');
    $second = $resolvers['get']([], 'field_test');

    $this->assertSame($mockFieldList, $first);
    $this->assertSame($first, $second);
    $this->assertSame(1, $callCount, 'Factory should only be called once');
  }

  /**
   * Tests ::get resolver throws for undefined fields.
   */
  public function testGetResolverThrowsForUndefinedField(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
    );
    $builder = new EntityDoubleBuilder($definition);
    $builder->setFieldListFactory(fn() => new \stdClass());
    $resolvers = $builder->getResolvers();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Field 'nonexistent' is not defined");

    $resolvers['get']([], 'nonexistent');
  }

  /**
   * Tests ::set resolver throws on immutable doubles.
   */
  public function testSetResolverThrowsOnImmutable(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
      mutable: FALSE,
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Cannot modify field 'field_test' on immutable");
    $this->expectExceptionMessage('createMutableEntityDouble()');

    $resolvers['set']([], 'field_test', 'new value');
  }

  /**
   * Tests ::set resolver clears the field cache on mutation.
   */
  public function testSetResolverClearsCache(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      fields: ['field_test' => new FieldDoubleDefinition('value')],
      interfaces: [FieldableEntityInterface::class],
      mutable: TRUE,
    );
    $mutableState = new MutableStateContainer();
    $builder = new EntityDoubleBuilder($definition, $mutableState);

    $callCount = 0;
    $builder->setFieldListFactory(function () use (&$callCount) {
      $callCount++;
      return new \stdClass();
    });

    $resolvers = $builder->getResolvers();

    // First access creates cached instance.
    $resolvers['get']([], 'field_test');
    $this->assertSame(1, $callCount);

    // Set clears cache and stores in mutable state.
    $resolvers['set']([], 'field_test', 'new value');

    // Next access should create new instance.
    $resolvers['get']([], 'field_test');
    // @phpstan-ignore method.impossibleType
    $this->assertSame(2, $callCount, 'Factory should be called again after set');

    // Verify mutable state was updated.
    $this->assertTrue($mutableState->hasFieldValue('field_test'));
    $this->assertSame('new value', $mutableState->getFieldValue('field_test'));
  }

  /**
   * Tests ::getMethodResolver for callable overrides.
   */
  public function testGetMethodResolverWithCallable(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      methods: ['getOwnerId' => fn(array $context) => $context['owner_id']],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolver = $builder->getMethodResolver('getOwnerId');

    $this->assertSame(42, $resolver(['owner_id' => 42]));
  }

  /**
   * Tests ::getMethodResolver for static value overrides.
   */
  public function testGetMethodResolverWithStaticValue(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      methods: ['isPublished' => TRUE],
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolver = $builder->getMethodResolver('isPublished');

    $this->assertTrue($resolver([]));
  }

  /**
   * Tests that ::getDefinition returns the definition.
   */
  public function testGetDefinition(): void {
    $definition = new EntityDoubleDefinition(entityType: 'node');
    $builder = new EntityDoubleBuilder($definition);

    $this->assertSame($definition, $builder->getDefinition());
  }

  /**
   * Tests resolvers receive definition in context.
   *
   * When ::withContext is called on the definition, the definition is added
   * to context and can be accessed by callbacks.
   */
  public function testResolversReceiveDefinitionInContext(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      bundle: 'article',
      id: function (array $context) {
        $def = $context[EntityDoubleDefinition::CONTEXT_KEY];
        assert($def instanceof EntityDoubleDefinition);
        return $def->bundle;
      },
    );

    // Simulate what factory does - withContext adds definition.
    $normalized = $definition->withContext([]);
    $builder = new EntityDoubleBuilder($normalized);
    $resolvers = $builder->getResolvers();

    // id() resolver should return 'article' (from definition->bundle).
    $this->assertSame('article', $resolvers['id']($normalized->context));
  }

  /**
   * Tests method override callbacks receive definition in context.
   */
  public function testMethodOverrideReceivesDefinitionInContext(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'taxonomy_term',
      methods: [
        'getVocabularyId' => function (array $context) {
          $def = $context[EntityDoubleDefinition::CONTEXT_KEY];
          assert($def instanceof EntityDoubleDefinition);
          return $def->entityType;
        },
      ],
    );

    $normalized = $definition->withContext([]);
    $builder = new EntityDoubleBuilder($normalized);
    $resolver = $builder->getMethodResolver('getVocabularyId');

    $this->assertSame('taxonomy_term', $resolver($normalized->context));
  }

  /**
   * Tests ::toUrl resolver with a static URL value.
   */
  public function testToUrlResolverWithStaticValue(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      url: '/node/1',
    );
    $builder = new EntityDoubleBuilder($definition);

    $urlDouble = new \stdClass();
    $capturedUrl = NULL;
    $builder->setUrlDoubleFactory(function (string $url) use ($urlDouble, &$capturedUrl) {
      $capturedUrl = $url;
      return $urlDouble;
    });

    $resolvers = $builder->getResolvers();
    $result = $resolvers['toUrl']([]);

    $this->assertSame($urlDouble, $result);
    $this->assertSame('/node/1', $capturedUrl);
  }

  /**
   * Tests ::toUrl resolver with a callable URL value.
   */
  public function testToUrlResolverWithCallable(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      url: static function (array $context): string {
        $id = $context['id'] ?? '';
        assert(is_scalar($id));
        return '/node/' . $id;
      },
    );
    $builder = new EntityDoubleBuilder($definition);

    $urlDouble = new \stdClass();
    $capturedUrl = NULL;
    $builder->setUrlDoubleFactory(function (string $url) use ($urlDouble, &$capturedUrl) {
      $capturedUrl = $url;
      return $urlDouble;
    });

    $resolvers = $builder->getResolvers();
    $result = $resolvers['toUrl'](['id' => 42]);

    $this->assertSame($urlDouble, $result);
    $this->assertSame('/node/42', $capturedUrl);
  }

  /**
   * Tests ::toUrl resolver caches the Url double.
   */
  public function testToUrlResolverCachesUrlDouble(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      url: '/node/1',
    );
    $builder = new EntityDoubleBuilder($definition);

    $callCount = 0;
    $urlDouble = new \stdClass();
    $builder->setUrlDoubleFactory(function () use ($urlDouble, &$callCount) {
      $callCount++;
      return $urlDouble;
    });

    $resolvers = $builder->getResolvers();
    $first = $resolvers['toUrl']([]);
    $second = $resolvers['toUrl']([]);

    $this->assertSame($urlDouble, $first);
    $this->assertSame($first, $second);
    $this->assertSame(1, $callCount, 'Factory should only be called once');
  }

  /**
   * Tests ::toUrl resolver ignores $rel and $options parameters.
   */
  public function testToUrlResolverIgnoresParameters(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      url: '/node/1',
    );
    $builder = new EntityDoubleBuilder($definition);

    $urlDouble = new \stdClass();
    $builder->setUrlDoubleFactory(fn() => $urlDouble);

    $resolvers = $builder->getResolvers();

    // All these should return the same cached double.
    $canonical = $resolvers['toUrl']([], 'canonical', []);
    $editForm = $resolvers['toUrl']([], 'edit-form', ['absolute' => TRUE]);

    $this->assertSame($urlDouble, $canonical);
    $this->assertSame($urlDouble, $editForm);
  }

  /**
   * Tests ::toUrl resolver throws when url not configured.
   */
  public function testToUrlResolverThrowsWhenNotConfigured(): void {
    $definition = new EntityDoubleDefinition(entityType: 'node');
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage("Method 'toUrl' requires url() to be configured");

    $resolvers['toUrl']([]);
  }

  /**
   * Tests ::toUrl resolver throws without factory.
   */
  public function testToUrlResolverThrowsWithoutFactory(): void {
    $definition = new EntityDoubleDefinition(
      entityType: 'node',
      url: '/node/1',
    );
    $builder = new EntityDoubleBuilder($definition);
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Url double factory not set');

    $resolvers['toUrl']([]);
  }

}
