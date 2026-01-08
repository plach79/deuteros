<?php

declare(strict_types=1);

namespace Deuteros\Double;

/**
 * Builds callable resolvers for field item list double methods.
 *
 * Produces framework-agnostic callable resolvers for "FieldItemListInterface"
 * methods. These resolvers are then wired to PHPUnit mocks or Prophecy doubles
 * by the adapter traits.
 */
final class FieldItemListDoubleBuilder {

  /**
   * Cached resolved value (for callable fields).
   */
  private mixed $resolvedValue = NULL;

  /**
   * Whether the value has been resolved.
   */
  private bool $valueResolved = FALSE;

  /**
   * Cached field item doubles keyed by delta.
   *
   * @var array<int, object>
   */
  private array $fieldItemCache = [];

  /**
   * Factory for creating field item doubles.
   *
   * @var callable|null
   */
  private mixed $fieldItemFactory = NULL;

  /**
   * Callback to update the mutable state.
   *
   * @var callable|null
   */
  private mixed $mutableStateUpdater = NULL;

  /**
   * Constructs a FieldItemListDoubleBuilder.
   *
   * @param \Deuteros\Double\FieldDoubleDefinition $definition
   *   The field double definition.
   * @param string $fieldName
   *   The field name.
   * @param bool $mutable
   *   Whether the parent entity is mutable.
   */
  public function __construct(
    private FieldDoubleDefinition $definition,
    private readonly string $fieldName,
    private readonly bool $mutable = FALSE,
  ) {
  }

  /**
   * Sets the factory for creating field item doubles.
   *
   * @param callable $factory
   *   A callable that accepts (int $delta, mixed $value) and returns a
   *   field item double.
   */
  public function setFieldItemFactory(callable $factory): void {
    $this->fieldItemFactory = $factory;
  }

  /**
   * Sets the callback to update mutable state.
   *
   * @param callable $updater
   *   A callable that accepts (string $fieldName, mixed $value).
   */
  public function setMutableStateUpdater(callable $updater): void {
    $this->mutableStateUpdater = $updater;
  }

  /**
   * Gets all field item list method resolvers.
   *
   * @return array<string, callable>
   *   Resolvers keyed by method name.
   */
  public function getResolvers(): array {
    return [
      'first' => $this->buildFirstResolver(),
      'isEmpty' => $this->buildIsEmptyResolver(),
      'getValue' => $this->buildGetValueResolver(),
      'get' => $this->buildGetResolver(),
      '__get' => $this->buildMagicGetResolver(),
      'setValue' => $this->buildSetValueResolver(),
      '__set' => $this->buildMagicSetResolver(),
      'referencedEntities' => $this->buildReferencedEntitiesResolver(),
      'getIterator' => $this->buildIteratorResolver(),
      'count' => $this->buildCountResolver(),
    ];
  }

  /**
   * Builds the ::first resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildFirstResolver(): callable {
    return function (array $context): ?object {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);

      if ($values === []) {
        return NULL;
      }

      return $this->getFieldItemDouble(0, $values[0], $context);
    };
  }

  /**
   * Builds the ::isEmpty resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildIsEmptyResolver(): callable {
    return function (array $context): bool {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);
      return $values === [];
    };
  }

  /**
   * Builds the ::getValue resolver.
   *
   * Returns field values as an array of associative arrays with property names
   * as keys, matching Drupal's "FieldItemListInterface"::getValue behavior.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildGetValueResolver(): callable {
    return function (array $context): array {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);
      // Wrap each item with property structure if needed.
      return array_map(
        fn(mixed $item) => $this->ensurePropertyStructure($item),
        $values
      );
    };
  }

  /**
   * Ensures a value has property structure.
   *
   * If the value is a scalar or doesn't have associative keys, wraps it with
   * the "value" property name. Values that already have property structure
   * (associative arrays) are returned unchanged.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return array<string, mixed>
   *   The value with property structure.
   */
  private function ensurePropertyStructure(mixed $value): array {
    // Already has property structure (associative array with string keys).
    if (is_array($value) && !array_is_list($value)) {
      /** @var array<string, mixed> $value */
      return $value;
    }

    // Wrap scalar or indexed array with 'value' property.
    return ['value' => $value];
  }

  /**
   * Builds the ::get resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildGetResolver(): callable {
    return function (array $context, int $delta): ?object {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);

      if (!isset($values[$delta])) {
        return NULL;
      }

      return $this->getFieldItemDouble($delta, $values[$delta], $context);
    };
  }

  /**
   * Builds the ::__get resolver.
   *
   * Proxies common property access to ::first item.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildMagicGetResolver(): callable {
    return function (array $context, string $property): mixed {
      /** @var array<string, mixed> $context */
      $firstItem = ($this->buildFirstResolver())($context);

      if ($firstItem === NULL) {
        return NULL;
      }

      // The field item double should have a __get resolver.
      // We'll call it directly since we control the double.
      assert(is_object($firstItem) && method_exists($firstItem, '__get'));
      return $firstItem->__get($property);
    };
  }

  /**
   * Builds the ::setValue resolver.
   *
   * Returns an anonymous object as a placeholder. The factory adapters are
   * responsible for replacing this with the actual field list instance to
   * support Drupal's fluent ::setValue interface (method chaining).
   *
   * @return callable
   *   The resolver callable.
   *
   * @see \Deuteros\Double\PhpUnit\MockEntityDoubleFactory::wireFieldListResolvers
   * @see \Deuteros\Double\Prophecy\ProphecyEntityDoubleFactory::wireFieldListResolvers
   */
  private function buildSetValueResolver(): callable {
    return function (array $context, mixed $values): object {
      if (!$this->mutable) {
        throw new \LogicException(
          "Cannot modify field '{$this->fieldName}' on immutable entity double. "
          . "Use createMutable() if you need to test mutations."
        );
      }

      // Update the mutable state.
      if ($this->mutableStateUpdater !== NULL) {
        ($this->mutableStateUpdater)($this->fieldName, $values);
      }

      // Reset cached values.
      $this->valueResolved = FALSE;
      $this->resolvedValue = NULL;
      $this->fieldItemCache = [];

      // Update the field definition. Note: This intentionally mutates the
      // builder state to track the new field value. This mutation is acceptable
      // because: (1) it only occurs on mutable doubles, (2) each field list
      // builder is single-use per entity double instance, and (3) the mutation
      // enables the mutable double to return updated values on subsequent
      // getter calls.
      $this->definition = new FieldDoubleDefinition($values);

      // Return placeholder object - adapters convert this to return $fieldList.
      return new class () {};
    };
  }

  /**
   * Builds the ::__set resolver.
   *
   * Proxies "value" property set to ::setValue.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildMagicSetResolver(): callable {
    $setValueResolver = $this->buildSetValueResolver();

    return function (array $context, string $property, mixed $value) use ($setValueResolver): void {
      /** @var array<string, mixed> $context */
      if ($property === 'value') {
        $setValueResolver($context, $value, TRUE);
      }
      else {
        throw new \LogicException("Setting property '$property' on field item list is not supported.");
      }
    };
  }

  /**
   * Builds the ::getIterator resolver.
   *
   * Returns an ArrayIterator over the field items, supporting foreach loops.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildIteratorResolver(): callable {
    return function (array $context): \Traversable {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);
      $items = [];
      foreach ($values as $delta => $value) {
        $items[] = $this->getFieldItemDouble($delta, $value, $context);
      }
      return new \ArrayIterator($items);
    };
  }

  /**
   * Builds the ::count resolver.
   *
   * Returns the number of field items in the list.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildCountResolver(): callable {
    return function (array $context): int {
      /** @var array<string, mixed> $context */
      return count($this->resolveValues($context));
    };
  }

  /**
   * Resolves the field values.
   *
   * Handles callable resolution and caching.
   *
   * @param array<string, mixed> $context
   *   The context for callback resolution.
   *
   * @return array<int, mixed>
   *   The resolved values as an indexed array.
   */
  private function resolveValues(array $context): array {
    if ($this->valueResolved) {
      return $this->normalizeToArray($this->resolvedValue);
    }

    $rawValue = $this->definition->getValue();

    // Resolve callable.
    if ($this->definition->isCallable()) {
      assert(is_callable($rawValue));
      $rawValue = $rawValue($context);
    }

    $this->resolvedValue = $rawValue;
    $this->valueResolved = TRUE;

    return $this->normalizeToArray($rawValue);
  }

  /**
   * Normalizes a value to an indexed array of field item values.
   *
   * Handles entity reference normalization via "EntityReferenceNormalizer"
   * for values that contain entity doubles.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return array<int, mixed>
   *   The normalized array.
   */
  private function normalizeToArray(mixed $value): array {
    // Check for entity references and normalize them.
    if (EntityReferenceNormalizer::containsEntityReferences($value)) {
      return EntityReferenceNormalizer::normalize($value);
    }

    return match (TRUE) {
      $value === NULL => [],
      is_array($value) && $this->isIndexedArray($value) => array_values($value),
      default => [$value],
    };
  }

  /**
   * Checks if an array is an indexed (sequential) array.
   *
   * @param array<mixed> $array
   *   The array to check.
   *
   * @return bool
   *   TRUE if indexed, FALSE if associative.
   */
  private function isIndexedArray(array $array): bool {
    if ($array === []) {
      return TRUE;
    }

    // If any value in the array is itself an array (representing a
    // multi-value field), treat the outer array as indexed.
    $firstKey = array_key_first($array);
    if (is_int($firstKey) && is_array($array[$firstKey])) {
      return TRUE;
    }

    // For simple values, treat as single-item field.
    return FALSE;
  }

  /**
   * Gets a field item double for a specific delta.
   *
   * @param int $delta
   *   The delta.
   * @param mixed $value
   *   The item value.
   * @param array<string, mixed> $context
   *   The context.
   *
   * @return object
   *   The field item double.
   */
  private function getFieldItemDouble(int $delta, mixed $value, array $context): object {
    if (isset($this->fieldItemCache[$delta])) {
      return $this->fieldItemCache[$delta];
    }

    if ($this->fieldItemFactory === NULL) {
      throw new \LogicException("Field item factory not set. Cannot create field item double.");
    }

    $fieldItem = ($this->fieldItemFactory)($delta, $value, $context);
    assert(is_object($fieldItem));
    $this->fieldItemCache[$delta] = $fieldItem;

    return $fieldItem;
  }

  /**
   * Gets the field name.
   *
   * @return string
   *   The field name.
   */
  public function getFieldName(): string {
    return $this->fieldName;
  }

  /**
   * Gets the field definition.
   *
   * @return \Deuteros\Double\FieldDoubleDefinition
   *   The field double definition.
   */
  public function getFieldDefinition(): FieldDoubleDefinition {
    return $this->definition;
  }

  /**
   * Builds the ::referencedEntities resolver.
   *
   * Returns an array of entity doubles keyed by delta, matching the
   * behavior of "EntityReferenceFieldItemListInterface"::referencedEntities.
   *
   * The returned resolver throws "LogicException" if any items have
   * "target_id" but no "entity".
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildReferencedEntitiesResolver(): callable {
    return function (array $context): array {
      /** @var array<string, mixed> $context */
      $values = $this->resolveValues($context);

      // Check if any items have target_id but no entity.
      if (EntityReferenceNormalizer::hasTargetIdOnlyItems($values)) {
        throw new \LogicException(sprintf(
          "Cannot call referencedEntities() on field '%s': field contains "
          . "target_id values without corresponding entity doubles. "
          . "Provide entity doubles or use ['entity' => NULL] for empty references.",
          $this->fieldName
        ));
      }

      return EntityReferenceNormalizer::extractEntities($values);
    };
  }

}
