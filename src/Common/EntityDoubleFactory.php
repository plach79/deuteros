<?php

declare(strict_types=1);

namespace Deuteros\Common;

use Deuteros\PhpUnit\MockEntityDoubleFactory;
use Deuteros\Prophecy\ProphecyEntityDoubleFactory;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Url;
use PHPUnit\Framework\TestCase;

/**
 * Abstract factory for creating entity doubles.
 *
 * DEUTEROS - Drupal Entity Unit Test Extensible Replacement Object Scaffolding.
 *
 * This factory provides value-object entity doubles for Drupal unit tests.
 * It allows testing code that depends on entity and field interfaces without
 * requiring Kernel tests, module enablement, storage, or services.
 *
 * Supported behaviors:
 * - Scalar and callback-based field values
 * - Multi-value field access via get(int $delta)
 * - Custom interfaces via the builder
 * - Method overrides for custom behavior
 * - Context propagation to callbacks
 * - Mutable doubles for testing entity modifications
 * - Entity reference traversal via entity property and referencedEntities()
 * - URL generation via url() builder method and toUrl()
 *
 * Explicitly unsupported behaviors (will throw):
 * - ::save, ::delete - requires entity storage
 * - ::access - requires access control services
 * - ::getTranslation - requires translation services
 *
 * This is a unit-test value object only. Use Kernel tests for behaviors that
 * require runtime services.
 *
 * @example Static values
 * ```php
 * $factory = EntityDoubleFactory::fromTest($this);
 * $entity = $factory->create(
 *   EntityDoubleDefinitionBuilder::create('node')
 *     ->bundle('article')
 *     ->id(1)
 *     ->field('field_title', 'Test Article')
 * );
 * $this->assertSame('Test Article', $entity->get('field_title')->value);
 * ```
 *
 * @example Callback-based resolution
 * ```php
 * $factory = EntityDoubleFactory::fromTest($this);
 * $entity = $factory->create(
 *   EntityDoubleDefinitionBuilder::create('node')
 *     ->bundle('article')
 *     ->field('field_date', fn($context) => $context['date']),
 *   ['date' => '2024-01-01'],
 * );
 * $this->assertSame('2024-01-01', $entity->get('field_date')->value);
 * ```
 */
abstract class EntityDoubleFactory implements EntityDoubleFactoryInterface {

  /**
   * Cache of generated runtime interfaces.
   *
   * Maps sorted interface list (as cache key) to generated interface name.
   *
   * @var array<string, class-string>
   */
  private static array $runtimeInterfaceCache = [];

  /**
   * Cache of generated trait stub classes.
   *
   * Maps base class + sorted trait list (as cache key) to stub class name.
   *
   * @var array<string, class-string>
   */
  private static array $traitStubClassCache = [];

  /**
   * Creates the appropriate factory based on the test case's available traits.
   *
   * Detects whether the test uses Prophecy ("ProphecyTrait") or PHPUnit mocks
   * and returns the matching factory implementation.
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   The test case instance.
   *
   * @return \Deuteros\Common\EntityDoubleFactoryInterface
   *   The appropriate factory implementation.
   */
  public static function fromTest(TestCase $test): EntityDoubleFactoryInterface {
    return method_exists($test, 'getProphet')
      ? ProphecyEntityDoubleFactory::fromTest($test)
      : MockEntityDoubleFactory::fromTest($test);
  }

  /**
   * Invokes a protected method on the test case.
   *
   * PHPUnit's mock creation methods are protected, but we need to call them
   * from outside the test case class.
   *
   * @param \PHPUnit\Framework\TestCase $test
   *   The test being run.
   * @param string $method
   *   The method name.
   * @param mixed ...$args
   *   The method arguments.
   *
   * @return mixed
   *   The method return value.
   */
  protected static function invokeNonPublicMethod(TestCase $test, string $method, mixed ...$args): mixed {
    $reflection = new \ReflectionMethod($test, $method);
    return $reflection->invoke($test, ...$args);
  }

  /**
   * {@inheritdoc}
   */
  public function create(EntityDoubleDefinition $definition, array $context = []): EntityInterface {
    $normalized = $definition
      ->withContext($context)
      ->withMutable(FALSE);
    return $this->buildEntityDouble($normalized);
  }

  /**
   * {@inheritdoc}
   */
  public function createMutable(EntityDoubleDefinition $definition, array $context = []): EntityInterface {
    $normalized = $definition
      ->withContext($context)
      ->withMutable(TRUE);
    return $this->buildEntityDouble($normalized);
  }

  /**
   * Builds an entity double from a definition.
   *
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The normalized entity double definition.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity double.
   */
  protected function buildEntityDouble(EntityDoubleDefinition $definition): EntityInterface {
    // Determine interfaces to mock.
    $interfaces = $this->resolveInterfaces($definition);

    // Create mutable state container if needed.
    $mutableState = $definition->mutable ? new MutableStateContainer() : NULL;

    // Create the builder.
    $builder = new EntityDoubleBuilder($definition, $mutableState);

    // Set up field list factory.
    $builder->setFieldListFactory(
      function (string $fieldName, FieldDoubleDefinition $fieldDoubleDefinition, array $context) use ($definition, $mutableState) {
        /** @var array<string, mixed> $context */
        return $this->createFieldItemListDouble($fieldName, $fieldDoubleDefinition, $definition, $mutableState, $context);
      }
    );

    // Set up URL double factory if URL is configured.
    if ($definition->url !== NULL) {
      $builder->setUrlDoubleFactory(
        function (string $url, array $context) {
          /** @var array<string, mixed> $context */
          return $this->createUrlDouble($url, $context);
        }
      );
    }

    // Create the double.
    $double = $this->createDoubleForInterfaces($interfaces);

    // Wire up resolvers.
    $this->wireEntityResolvers($double, $builder, $definition);

    // Wire guardrails for unsupported methods.
    $this->wireGuardrails($double, $definition, $interfaces);

    $entity = $this->instantiateDouble($double);

    // If traits are specified, wrap the entity in a trait stub.
    if ($definition->traits !== []) {
      $entity = $this->createTraitStub($entity, $definition->traits);
    }

    return $entity;
  }

  /**
   * Resolves the interfaces to mock.
   *
   * Deduplicates interfaces to avoid redundancy when interfaces extend each
   * other (e.g., if both "FieldableEntityInterface" and "EntityInterface" are
   * declared, only "FieldableEntityInterface" is kept since it already extends
   * "EntityInterface").
   *
   * Also ensures "EntityInterface" is always covered by at least one of the
   * declared interfaces.
   *
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The entity double definition.
   *
   * @return list<class-string>
   *   The interfaces to mock.
   */
  protected function resolveInterfaces(EntityDoubleDefinition $definition): array {
    // Collect all declared interfaces.
    $interfaces = $definition->interfaces;

    // If no interfaces declared, just use EntityInterface.
    if ($interfaces === []) {
      return [EntityInterface::class];
    }

    // Filter out interfaces that are parents of other interfaces in the list.
    // This avoids redundancy (if A extends B and both are declared, keep only
    // A).
    $filtered = [];
    foreach ($interfaces as $interface) {
      $isParent = FALSE;
      foreach ($interfaces as $other) {
        if ($interface !== $other && is_a($other, $interface, TRUE)) {
          // $interface is a parent of $other, skip it.
          $isParent = TRUE;
          break;
        }
      }
      if (!$isParent) {
        $filtered[] = $interface;
      }
    }

    // If "EntityInterface" is not covered by any declared interface, add it.
    $coversEntity = FALSE;
    foreach ($filtered as $interface) {
      if (is_a($interface, EntityInterface::class, TRUE)) {
        $coversEntity = TRUE;
        break;
      }
    }
    if (!$coversEntity) {
      array_unshift($filtered, EntityInterface::class);
    }

    return $filtered;
  }

  /**
   * Creates a field item list double.
   *
   * Automatically detects entity references in the field value and creates an
   * "EntityReferenceFieldItemListInterface" double when appropriate.
   *
   * @param string $fieldName
   *   The field name.
   * @param \Deuteros\Common\FieldDoubleDefinition $fieldDoubleDefinition
   *   The field double definition.
   * @param \Deuteros\Common\EntityDoubleDefinition $entityDoubleDefinition
   *   The entity double definition.
   * @param \Deuteros\Common\MutableStateContainer|null $mutableState
   *   The mutable state container.
   * @param array<string, mixed> $context
   *   The context.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>
   *   The field item list double.
   */
  protected function createFieldItemListDouble(string $fieldName, FieldDoubleDefinition $fieldDoubleDefinition, EntityDoubleDefinition $entityDoubleDefinition, ?MutableStateContainer $mutableState, array $context): FieldItemListInterface {
    $builder = new FieldItemListDoubleBuilder($fieldDoubleDefinition, $fieldName, $entityDoubleDefinition->mutable);

    // Set up field item factory.
    $builder->setFieldItemFactory(
      function (int $delta, mixed $value, array $context) use ($fieldName, $entityDoubleDefinition) {
        /** @var array<string, mixed> $context */
        return $this->createFieldItemDouble($delta, $value, $fieldName, $entityDoubleDefinition->mutable, $context);
      }
    );

    // Set up mutable state updater if applicable.
    if ($mutableState !== NULL) {
      $builder->setMutableStateUpdater(
        fn(string $name, mixed $value) => $mutableState->setFieldValue($name, $value)
      );
    }

    // Detect entity references to determine the interface.
    $hasEntityReferences = $this->detectEntityReferences($fieldDoubleDefinition, $context);

    // Create the double with appropriate interface.
    $double = $hasEntityReferences
      ? $this->createEntityReferenceFieldListDoubleObject()
      : $this->createFieldListDoubleObject();

    // Wire up resolvers.
    $this->wireFieldListResolvers($double, $builder, $entityDoubleDefinition, $context, $hasEntityReferences);

    return $this->instantiateFieldListDouble($double);
  }

  /**
   * Detects if a field contains entity references.
   *
   * @param \Deuteros\Common\FieldDoubleDefinition $definition
   *   The field definition.
   * @param array<string, mixed> $context
   *   The context for callback resolution.
   *
   * @return bool
   *   TRUE if entity references detected.
   */
  private function detectEntityReferences(FieldDoubleDefinition $definition, array $context): bool {
    $value = $definition->getValue();

    // Resolve callable first.
    if ($definition->isCallable()) {
      assert(is_callable($value));
      $value = $value($context);
    }

    return EntityReferenceNormalizer::containsEntityReferences($value);
  }

  /**
   * Creates a field item double.
   *
   * @param int $delta
   *   The delta.
   * @param mixed $value
   *   The item value.
   * @param string $fieldName
   *   The field name.
   * @param bool $mutable
   *   Whether the entity is mutable.
   * @param array<string, mixed> $context
   *   The context.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The field item double.
   */
  protected function createFieldItemDouble(int $delta, mixed $value, string $fieldName, bool $mutable, array $context): FieldItemInterface {
    $builder = new FieldItemDoubleBuilder($value, $delta, $fieldName, $mutable);

    // Create the double.
    $double = $this->createFieldItemDoubleObject();

    // Wire up resolvers.
    $this->wireFieldItemResolvers($double, $builder, $mutable, $delta, $fieldName, $context);

    return $this->instantiateFieldItemDouble($double);
  }

  /**
   * Gets or creates a runtime interface with magic accessor support.
   *
   * Generates a single interface that extends all requested interfaces and
   * declares ::__get/::__set methods for magic property access.
   *
   * @param list<class-string> $interfaces
   *   The interfaces to extend.
   *
   * @return class-string
   *   The runtime interface name.
   */
  protected function getOrCreateRuntimeInterface(array $interfaces): string {
    // Sort for deterministic cache key.
    $sorted = $interfaces;
    sort($sorted);
    $cacheKey = implode('|', $sorted);

    if (isset(self::$runtimeInterfaceCache[$cacheKey])) {
      return self::$runtimeInterfaceCache[$cacheKey];
    }

    // Generate unique interface name.
    $hash = substr(md5($cacheKey), 0, 12);
    /** @var class-string $interfaceName */
    $interfaceName = "Deuteros\\Generated\\RuntimeInterface_{$hash}";

    if (!interface_exists($interfaceName, FALSE)) {
      $this->declareRuntimeInterface($interfaceName, $interfaces);
    }

    self::$runtimeInterfaceCache[$cacheKey] = $interfaceName;
    return $interfaceName;
  }

  /**
   * Declares a runtime interface via eval.
   *
   * @param string $interfaceName
   *   The fully-qualified interface name to declare.
   * @param list<class-string> $interfaces
   *   The interfaces to extend.
   */
  private function declareRuntimeInterface(string $interfaceName, array $interfaces): void {
    $parts = explode('\\', $interfaceName);
    $shortName = array_pop($parts);
    $namespace = implode('\\', $parts);

    $extends = implode(', ', array_map(
      fn(string $interface) => '\\' . $interface,
      $interfaces
    ));

    $code = sprintf(
      'namespace %s { interface %s extends %s { '
      . 'public function __get(string $name): mixed; '
      . 'public function __set(string $name, mixed $value): void; '
      . '} }',
      $namespace,
      $shortName,
      $extends
    );

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    eval($code);
  }

  /**
   * Creates a Url double.
   *
   * Creates a Url mock/prophecy with ::toString wired to return the URL string
   * or a GeneratedUrl double when $collect_bubbleable_metadata is TRUE.
   *
   * @param string $url
   *   The URL string.
   * @param array<string, mixed> $context
   *   The context.
   *
   * @return \Drupal\Core\Url
   *   The Url double.
   */
  protected function createUrlDouble(string $url, array $context): Url {
    $urlBuilder = new UrlDoubleBuilder($url);

    // Set up GeneratedUrl factory.
    $urlBuilder->setGeneratedUrlFactory(
      function (string $generatedUrl) use ($context) {
        return $this->createGeneratedUrlDouble($generatedUrl, $context);
      }
    );

    // Create the double.
    $double = $this->createUrlDoubleObject();

    // Wire up resolvers.
    $this->wireUrlResolvers($double, $urlBuilder, $context);

    return $this->instantiateUrlDouble($double);
  }

  /**
   * Creates a GeneratedUrl double.
   *
   * @param string $url
   *   The URL string.
   * @param array<string, mixed> $context
   *   The context.
   *
   * @return object
   *   The GeneratedUrl double.
   */
  protected function createGeneratedUrlDouble(string $url, array $context): object {
    $double = $this->createGeneratedUrlDoubleObject();
    $this->wireGeneratedUrlResolvers($double, $url);
    return $this->instantiateGeneratedUrlDouble($double);
  }

  /**
   * Creates a trait stub that extends the entity double and uses the traits.
   *
   * Generates a stub class dynamically that extends the double's class and
   * applies the specified traits, then copies the internal state from the
   * original double to the stub instance.
   *
   * @param \Drupal\Core\Entity\EntityInterface $double
   *   The entity double.
   * @param list<class-string> $traits
   *   The traits to apply.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The trait stub instance.
   */
  protected function createTraitStub(EntityInterface $double, array $traits): EntityInterface {
    $baseClassName = get_class($double);
    $stubClassName = $this->getOrCreateTraitStubClass($baseClassName, $traits);
    return $this->instantiateTraitStub($double, $stubClassName);
  }

  /**
   * Gets or creates a trait stub class.
   *
   * @param class-string $baseClassName
   *   The base class to extend.
   * @param list<class-string> $traits
   *   The traits to apply.
   *
   * @return class-string
   *   The trait stub class name.
   */
  private function getOrCreateTraitStubClass(string $baseClassName, array $traits): string {
    // Sort traits for deterministic cache key.
    $sortedTraits = $traits;
    sort($sortedTraits);
    $cacheKey = $baseClassName . '|' . implode('|', $sortedTraits);

    if (isset(self::$traitStubClassCache[$cacheKey])) {
      return self::$traitStubClassCache[$cacheKey];
    }

    // Generate unique stub class name.
    $hash = substr(md5($cacheKey), 0, 12);
    /** @var class-string $stubClassName */
    $stubClassName = "Deuteros\\Generated\\TraitStub_{$hash}";

    if (!class_exists($stubClassName, FALSE)) {
      $this->declareTraitStubClass($stubClassName, $baseClassName, $sortedTraits);
    }

    self::$traitStubClassCache[$cacheKey] = $stubClassName;
    return $stubClassName;
  }

  /**
   * Declares a trait stub class via eval.
   *
   * @param string $stubClassName
   *   The fully-qualified stub class name to declare.
   * @param string $baseClassName
   *   The base class to extend.
   * @param list<class-string> $traits
   *   The traits to apply.
   */
  private function declareTraitStubClass(string $stubClassName, string $baseClassName, array $traits): void {
    $parts = explode('\\', $stubClassName);
    $shortName = array_pop($parts);
    $namespace = implode('\\', $parts);

    $traitUses = implode(', ', array_map(
      fn(string $trait) => '\\' . $trait,
      $traits
    ));

    $code = sprintf(
      'namespace %s { final class %s extends \\%s { use %s; } }',
      $namespace,
      $shortName,
      $baseClassName,
      $traitUses
    );

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged
    eval($code);
  }

  /**
   * Instantiates a trait stub by copying state from the entity double.
   *
   * Creates a new instance of the stub class and copies the internal state
   * from the original double. The implementation is adapter-specific since
   * PHPUnit mocks and Prophecy revealed objects have different internal
   * structures.
   *
   * @param \Drupal\Core\Entity\EntityInterface $double
   *   The entity double.
   * @param class-string $stubClassName
   *   The stub class name.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The trait stub instance.
   */
  abstract protected function instantiateTraitStub(EntityInterface $double, string $stubClassName): EntityInterface;

  /**
   * Creates a double for the given interfaces.
   *
   * @param list<class-string> $interfaces
   *   The interfaces to implement.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createDoubleForInterfaces(array $interfaces): object;

  /**
   * Wires entity method resolvers to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param \Deuteros\Common\EntityDoubleBuilder $builder
   *   The entity double builder.
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The entity double definition.
   */
  abstract protected function wireEntityResolvers(object $double, EntityDoubleBuilder $builder, EntityDoubleDefinition $definition): void;

  /**
   * Wires guardrail exceptions to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The entity double definition.
   * @param list<class-string> $interfaces
   *   The interfaces being mocked.
   */
  abstract protected function wireGuardrails(object $double, EntityDoubleDefinition $definition, array $interfaces): void;

  /**
   * Creates a field item list double object.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createFieldListDoubleObject(): object;

  /**
   * Creates an entity reference field item list double object.
   *
   * Returns a double implementing "EntityReferenceFieldItemListInterface"
   * which extends "FieldItemListInterface" with the ::referencedEntities
   * method.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createEntityReferenceFieldListDoubleObject(): object;

  /**
   * Wires field item list method resolvers to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param \Deuteros\Common\FieldItemListDoubleBuilder $builder
   *   The field item list builder.
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The entity double definition.
   * @param array<string, mixed> $context
   *   The context.
   * @param bool $hasEntityReferences
   *   Whether this field contains entity references. When TRUE, the
   *   ::referencedEntities resolver should be wired.
   */
  abstract protected function wireFieldListResolvers(object $double, FieldItemListDoubleBuilder $builder, EntityDoubleDefinition $definition, array $context, bool $hasEntityReferences = FALSE): void;

  /**
   * Creates a field item double object.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createFieldItemDoubleObject(): object;

  /**
   * Wires field item method resolvers to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param \Deuteros\Common\FieldItemDoubleBuilder $builder
   *   The field item builder.
   * @param bool $mutable
   *   Whether the entity is mutable.
   * @param int $delta
   *   The field item delta.
   * @param string $fieldName
   *   The field name.
   * @param array<string, mixed> $context
   *   The context.
   */
  abstract protected function wireFieldItemResolvers(object $double, FieldItemDoubleBuilder $builder, bool $mutable, int $delta, string $fieldName, array $context): void;

  /**
   * Reveals the entity double (converts mock/prophecy to usable object).
   *
   * @param object $double
   *   The mock/prophecy object.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The revealed entity.
   */
  abstract protected function instantiateDouble(object $double): EntityInterface;

  /**
   * Reveals a field item list double.
   *
   * @param object $double
   *   The mock/prophecy object.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>
   *   The revealed field item list.
   */
  abstract protected function instantiateFieldListDouble(object $double): FieldItemListInterface;

  /**
   * Reveals a field item double.
   *
   * @param object $double
   *   The mock/prophecy object.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The revealed field item.
   */
  abstract protected function instantiateFieldItemDouble(object $double): FieldItemInterface;

  /**
   * Creates a Url double object.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createUrlDoubleObject(): object;

  /**
   * Creates a GeneratedUrl double object.
   *
   * @return object
   *   The mock/prophecy object (not revealed).
   */
  abstract protected function createGeneratedUrlDoubleObject(): object;

  /**
   * Wires Url method resolvers to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param \Deuteros\Common\UrlDoubleBuilder $builder
   *   The URL builder.
   * @param array<string, mixed> $context
   *   The context.
   */
  abstract protected function wireUrlResolvers(object $double, UrlDoubleBuilder $builder, array $context): void;

  /**
   * Wires GeneratedUrl method resolvers to the double.
   *
   * @param object $double
   *   The mock/prophecy object.
   * @param string $url
   *   The URL string.
   */
  abstract protected function wireGeneratedUrlResolvers(object $double, string $url): void;

  /**
   * Reveals a Url double.
   *
   * @param object $double
   *   The mock/prophecy object.
   *
   * @return \Drupal\Core\Url
   *   The revealed Url.
   */
  abstract protected function instantiateUrlDouble(object $double): Url;

  /**
   * Reveals a GeneratedUrl double.
   *
   * @param object $double
   *   The mock/prophecy object.
   *
   * @return object
   *   The revealed GeneratedUrl.
   */
  abstract protected function instantiateGeneratedUrlDouble(object $double): object;

}
