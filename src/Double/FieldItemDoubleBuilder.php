<?php

declare(strict_types=1);

namespace Deuteros\Double;

/**
 * Builds callable resolvers for field item double methods.
 *
 * Produces framework-agnostic callable resolvers for "FieldItemInterface"
 * methods. These resolvers are then wired to PHPUnit mocks or Prophecy doubles
 * by the adapter traits.
 */
final class FieldItemDoubleBuilder {
  /**
   * The item value (mutable for mutable doubles).
   */
  private mixed $value;

  /**
   * Constructs a FieldItemDoubleBuilder.
   *
   * @param mixed $value
   *   The item value.
   * @param int $delta
   *   The delta of this item.
   * @param string $fieldName
   *   The field name.
   * @param bool $mutable
   *   Whether the parent entity is mutable.
   */
  public function __construct(
    mixed $value,
    private readonly int $delta,
    private readonly string $fieldName,
    private readonly bool $mutable = FALSE,
  ) {
    $this->value = $value;
  }

  /**
   * Gets all field item method resolvers.
   *
   * @return array<string, callable>
   *   Resolvers keyed by method name.
   */
  public function getResolvers(): array {
    return [
      '__get' => $this->buildMagicGetResolver(),
      'getValue' => $this->buildGetValueResolver(),
      'setValue' => $this->buildSetValueResolver(),
      '__set' => $this->buildMagicSetResolver(),
      'isEmpty' => $this->buildIsEmptyResolver(),
    ];
  }

  /**
   * Builds the ::__get resolver.
   *
   * Provides access to common field properties: "value", "target_id", etc.
   * Also handles the "entity" property for entity reference fields.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildMagicGetResolver(): callable {
    return function (array $context, string $property): mixed {
      // Handle 'entity' property - return the stored entity double.
      // This takes precedence over array key lookup since entity references
      // store the entity object directly in the 'entity' key.
      if ($property === 'entity' && is_array($this->value) && isset($this->value['entity'])) {
        return $this->value['entity'];
      }

      // If value is an array (e.g., ['target_id' => 1]), look up the property.
      if (is_array($this->value)) {
        return $this->value[$property] ?? NULL;
      }

      // For scalar values, 'value' returns the scalar.
      if ($property === 'value') {
        return $this->value;
      }

      return NULL;
    };
  }

  /**
   * Builds the ::getValue resolver.
   *
   * Returns the field item value as an associative array with property names
   * as keys, matching Drupal's "FieldItemInterface"::getValue behavior.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildGetValueResolver(): callable {
    return fn(array $context): array => $this->ensurePropertyStructure($this->value);
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
   * Builds the ::setValue resolver.
   *
   * Returns an anonymous object as a placeholder. The factory adapters are
   * responsible for replacing this with the actual field item instance to
   * support Drupal's fluent ::setValue interface (method chaining).
   *
   * @return callable
   *   The resolver callable.
   *
   * @see \Deuteros\Double\PhpUnit\MockEntityDoubleFactory::wireFieldItemResolvers
   * @see \Deuteros\Double\Prophecy\ProphecyEntityDoubleFactory::wireFieldItemResolvers
   */
  private function buildSetValueResolver(): callable {
    return function (array $context, mixed $value): object {
      if (!$this->mutable) {
        throw new \LogicException(
         "Cannot modify field '{$this->fieldName}' item at delta {$this->delta} on immutable entity double. "
          . "Use createMutable() if you need to test mutations."
        );
      }

      $this->value = $value;

      // Return placeholder object - adapters convert this to return $fieldItem.
      return new class () {};
    };
  }

  /**
   * Builds the ::__set resolver.
   *
   * Proxies property set to ::setValue for "value" property.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildMagicSetResolver(): callable {
    return function (array $context, string $property, mixed $value): void {
      if (!$this->mutable) {
        throw new \LogicException(
          "Cannot modify property '$property' on immutable entity double. "
          . "Use createMutable() if you need to test mutations."
        );
      }

      if ($property === 'value') {
        $this->value = $value;
      }
      elseif (is_array($this->value)) {
        $this->value[$property] = $value;
      }
      else {
        throw new \LogicException("Cannot set property '$property' on scalar field item.");
      }
    };
  }

  /**
   * Builds the ::isEmpty resolver.
   *
   * @return callable
   *   The resolver callable.
   */
  private function buildIsEmptyResolver(): callable {
    return fn(array $context): bool => $this->value === NULL || $this->value === '';
  }

  /**
   * Gets the item value.
   *
   * @return mixed
   *   The item value.
   */
  public function getValue(): mixed {
    return $this->value;
  }

  /**
   * Gets the delta.
   *
   * @return int
   *   The delta.
   */
  public function getDelta(): int {
    return $this->delta;
  }

}
