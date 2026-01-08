<?php

declare(strict_types=1);

namespace Deuteros\Double;

/**
 * Builds callable resolvers for entity double methods.
 *
 * Produces framework-agnostic callable resolvers for core entity methods. These
 * resolvers are then wired to PHPUnit mocks or Prophecy doubles by the adapter
 * traits.
 *
 * Method resolution order:
 * 1. methodOverrides from EntityDoubleDefinition.
 * 2. Core entity/field resolvers from this builder.
 * 3. Guardrail failure (handled by adapters).
 */
final class EntityDoubleBuilder {
  /**
   * Cached field item list doubles.
   *
   * @var array<string, object>
   */
  private array $fieldListCache = [];

  /**
   * Factory for creating field item list doubles.
   *
   * @var callable|null
   */
  private mixed $fieldListFactory = NULL;

  /**
   * Factory for creating Url doubles.
   *
   * @var callable|null
   */
  private mixed $urlDoubleFactory = NULL;

  /**
   * Cached Url double.
   *
   * @var object|null
   */
  private ?object $urlDoubleCache = NULL;

  /**
   * Constructs an EntityDoubleBuilder.
   *
   * @param \Deuteros\Double\EntityDoubleDefinition $definition
   *   The entity double definition.
   * @param \Deuteros\Double\MutableStateContainer|null $mutableState
   *   The mutable state container, or NULL for immutable doubles.
   */
  public function __construct(
    private readonly EntityDoubleDefinition $definition,
    private readonly ?MutableStateContainer $mutableState = NULL,
  ) {}

  /**
   * Sets the factory for creating field item list doubles.
   *
   * @param callable $factory
   *   A callable that accepts
   *   (string $fieldName, FieldDoubleDefinition $definition) and returns a
   *   item list double.
   */
  public function setFieldListFactory(callable $factory): void {
    $this->fieldListFactory = $factory;
  }

  /**
   * Sets the factory for creating Url doubles.
   *
   * @param callable $factory
   *   A callable that accepts (string $url, array $context) and returns a Url
   *   double.
   */
  public function setUrlDoubleFactory(callable $factory): void {
    $this->urlDoubleFactory = $factory;
  }

  /**
   * Gets all core entity method resolvers.
   *
   * @return array<string, callable>
   *   Resolvers keyed by method name.
   */
  public function getResolvers(): array {
    return [
      'id' => $this->buildIdResolver(),
      'uuid' => $this->buildUuidResolver(),
      'label' => $this->buildLabelResolver(),
      'bundle' => $this->buildBundleResolver(),
      'getEntityTypeId' => $this->buildEntityTypeIdResolver(),
      'hasField' => $this->buildHasFieldResolver(),
      'get' => $this->buildGetResolver(),
      '__get' => $this->buildMagicGetResolver(),
      'set' => $this->buildSetResolver(),
      'toUrl' => $this->buildToUrlResolver(),
    ];
  }

  /**
   * Builds the ::id resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildIdResolver(): callable {
    return function (array $context): mixed {
      /** @var array<string, mixed> $context */
      return $this->resolveValue($this->definition->id, $context);
    };
  }

  /**
   * Builds the ::uuid resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildUuidResolver(): callable {
    return function (array $context): mixed {
      /** @var array<string, mixed> $context */
      return $this->resolveValue($this->definition->uuid, $context);
    };
  }

  /**
   * Builds the ::label resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildLabelResolver(): callable {
    return function (array $context): mixed {
      /** @var array<string, mixed> $context */
      return $this->resolveValue($this->definition->label, $context);
    };
  }

  /**
   * Builds the ::bundle resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildBundleResolver(): callable {
    return fn(array $context): string => $this->definition->bundle;
  }

  /**
   * Builds the ::getEntityTypeId resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildEntityTypeIdResolver(): callable {
    return fn(array $context): string => $this->definition->entityType;
  }

  /**
   * Builds the ::hasField resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildHasFieldResolver(): callable {
    return fn(array $context, string $fieldName): bool => $this->definition->hasField($fieldName);
  }

  /**
   * Builds the ::get resolver.
   *
   * Returns a "FieldItemListInterface" double for the requested field.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildGetResolver(): callable {
    /**
     * @param array<string, mixed> $context
     */
    return function (array $context, string $fieldName): object {
      // Return cached field list if available.
      if (isset($this->fieldListCache[$fieldName])) {
        return $this->fieldListCache[$fieldName];
      }

      $definition = $this->getFieldDefinitionForAccess($fieldName);

      if ($this->fieldListFactory === NULL) {
        throw new \LogicException("Field list factory not set. Cannot create field list double for '$fieldName'.");
      }

      // Create and cache the field list double.
      $fieldList = ($this->fieldListFactory)($fieldName, $definition, $context);
      assert(is_object($fieldList));
      $this->fieldListCache[$fieldName] = $fieldList;

      return $fieldList;
    };
  }

  /**
   * Builds the ::__get resolver.
   *
   * Proxies to ::get for field access.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildMagicGetResolver(): callable {
    $getResolver = $this->buildGetResolver();
    /**
     * @param array<string, mixed> $context
     */
    return function (array $context, string $fieldName) use ($getResolver): object {
      $result = $getResolver($context, $fieldName);
      assert(is_object($result));
      return $result;
    };
  }

  /**
   * Builds the ::set resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildSetResolver(): callable {
    return function (array $context, string $fieldName, mixed $value, bool $notify = TRUE): object {
      if ($this->mutableState === NULL) {
        throw new \LogicException(
              "Cannot modify field '$fieldName' on immutable entity double. "
              . "Use createMutable() if you need to test mutations."
          );
      }

      if (!$this->definition->hasField($fieldName)) {
        throw new \InvalidArgumentException("Field '$fieldName' is not defined on this entity double.");
      }

      // Store the new value in mutable state.
      $this->mutableState->setFieldValue($fieldName, $value);

      // Clear the field list cache so the new value is picked up.
      unset($this->fieldListCache[$fieldName]);

      // Return $this equivalent - adapter must handle this.
      return new class () {};
    };
  }

  /**
   * Builds the ::toUrl resolver.
   *
   * Returns a Url double when url is configured, throws otherwise.
   * The $rel and $options parameters are accepted but ignored.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildToUrlResolver(): callable {
    return function (array $context): object {
      // Return cached Url double if available.
      if ($this->urlDoubleCache !== NULL) {
        return $this->urlDoubleCache;
      }

      $url = $this->definition->url;
      if ($url === NULL) {
        throw new \LogicException(
          "Method 'toUrl' requires url() to be configured in the entity double definition. "
          . "Add ->url('/path/to/entity') to your builder."
        );
      }

      // Resolve callable if needed.
      /** @var array<string, mixed> $context */
      $resolvedUrl = $this->resolveValue($url, $context);
      if (!is_string($resolvedUrl)) {
        throw new \LogicException(
          "The url() value must resolve to a string. Got: " . gettype($resolvedUrl)
        );
      }

      if ($this->urlDoubleFactory === NULL) {
        throw new \LogicException("Url double factory not set. Cannot create Url double.");
      }

      // Create and cache the Url double.
      $urlDouble = ($this->urlDoubleFactory)($resolvedUrl, $context);
      assert(is_object($urlDouble));
      $this->urlDoubleCache = $urlDouble;

      return $urlDouble;
    };
  }

  /**
   * Gets the field definition for a field access.
   *
   * Checks mutable state first, then falls back to definition.
   *
   * @param string $fieldName
   *   The field name.
   *
   * @return \Deuteros\Double\FieldDoubleDefinition
   *   The field double definition.
   *
   * @throws \InvalidArgumentException
   *   If the field is not defined.
   */
  private function getFieldDefinitionForAccess(string $fieldName): FieldDoubleDefinition {
    // Check mutable state first.
    if ($this->mutableState !== NULL && $this->mutableState->hasFieldValue($fieldName)) {
      return new FieldDoubleDefinition($this->mutableState->getFieldValue($fieldName));
    }

    $definition = $this->definition->getField($fieldName);
    if ($definition === NULL) {
      throw new \InvalidArgumentException("Field '$fieldName' is not defined on this entity double.");
    }

    return $definition;
  }

  /**
   * Resolves a potentially callable value.
   *
   * @param mixed $value
   *   The value to resolve.
   * @param array<string, mixed> $context
   *   The context for callback resolution.
   * @param mixed ...$args
   *   Additional arguments for the callback.
   *
   * @return mixed
   *   The resolved value.
   */
  private function resolveValue(mixed $value, array $context, mixed ...$args): mixed {
    return match (TRUE) {
      is_callable($value) => $value($context, ...$args),
      default => $value,
    };
  }

  /**
   * Gets the entity definition.
   *
   * @return \Deuteros\Double\EntityDoubleDefinition
   *   The entity double definition.
   */
  public function getDefinition(): EntityDoubleDefinition {
    return $this->definition;
  }

  /**
   * Gets the resolver for a method override.
   *
   * @param string $method
   *   The method name.
   *
   * @return callable
   *   The resolver for the override.
   */
  public function getMethodResolver(string $method): callable {
    $override = $this->definition->getMethod($method);

    if (is_callable($override)) {
      return fn(array $context, mixed ...$args): mixed => $override($context, ...$args);
    }

    // Static value.
    return fn(array $context, mixed ...$args): mixed => $override;
  }

}
