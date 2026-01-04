<?php

declare(strict_types=1);

namespace Deuteros\Common;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Immutable value object representing an entity definition.
 *
 * Stores all configuration needed to create an entity double:
 * - Entity metadata (type, bundle, id, uuid, label)
 * - Field definitions
 * - Interfaces to implement
 * - Method overrides for custom behavior
 * - Context for callback resolution.
 *
 * @see \Deuteros\Common\EntityDoubleBuilder
 */
final readonly class EntityDoubleDefinition {

  /**
   * Context key for accessing the entity definition in callbacks.
   *
   * This key is reserved by Deuteros. User callbacks can access the entity
   * definition via `$context[EntityDoubleDefinition::CONTEXT_KEY]`.
   */
  public const string CONTEXT_KEY = '_definition';

  /**
   * The bundle (defaults to entityType if not provided).
   */
  public string $bundle;

  /**
   * Constructs an EntityDoubleDefinition.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param mixed $id
   *   The entity ID.
   * @param mixed $uuid
   *   The entity UUID.
   * @param mixed $label
   *   The entity label.
   * @param array<string, \Deuteros\Common\FieldDoubleDefinition> $fields
   *   Field double definitions keyed by field name.
   * @param list<class-string> $interfaces
   *   List of interfaces to implement.
   * @param array<string, callable|mixed> $methods
   *   Method overrides keyed by method name.
   * @param array<string, mixed> $context
   *   Context data for callback resolution.
   * @param bool $mutable
   *   Whether the entity double should be mutable.
   * @param class-string|null $primaryInterface
   *   The primary interface for improved error messages.
   * @param bool $lenient
   *   Whether to use lenient mode (return null for unconfigured methods).
   * @param list<class-string> $traits
   *   Traits to apply to the entity double.
   * @param mixed $url
   *   The URL for ::toUrl (scalar or callable).
   *
   * @throws \InvalidArgumentException
   *   If fields are defined but FieldableEntityInterface is not in interfaces.
   */
  public function __construct(
    public string $entityType,
    string $bundle = '',
    public mixed $id = NULL,
    public mixed $uuid = NULL,
    public mixed $label = NULL,
    public array $fields = [],
    public array $interfaces = [],
    public array $methods = [],
    public array $context = [],
    public bool $mutable = FALSE,
    public ?string $primaryInterface = NULL,
    public bool $lenient = FALSE,
    public array $traits = [],
    public mixed $url = NULL,
  ) {
    // Validate that fields are only used with "FieldableEntityInterface".
    if ($fields !== [] && !in_array(FieldableEntityInterface::class, $interfaces, TRUE)) {
      throw new \InvalidArgumentException(
        "Fields can only be defined when FieldableEntityInterface is listed in interfaces. "
        . "Add FieldableEntityInterface::class to the 'interfaces' array."
      );
    }

    $this->bundle = $bundle !== '' ? $bundle : $entityType;
  }

  /**
   * Checks if a specific interface is implemented.
   *
   * @param class-string $interface
   *   The fully qualified interface name.
   *
   * @return bool
   *   TRUE if the interface is listed, FALSE otherwise.
   */
  public function hasInterface(string $interface): bool {
    return in_array($interface, $this->interfaces, TRUE);
  }

  /**
   * Checks if a method override exists.
   *
   * @param string $method
   *   The method name.
   *
   * @return bool
   *   TRUE if an override exists, FALSE otherwise.
   */
  public function hasMethod(string $method): bool {
    return array_key_exists($method, $this->methods);
  }

  /**
   * Gets a method override.
   *
   * @param string $method
   *   The method name.
   *
   * @return callable|mixed|null
   *   The override value, or NULL if not defined.
   */
  public function getMethod(string $method): mixed {
    return $this->methods[$method] ?? NULL;
  }

  /**
   * Checks if a field is defined.
   *
   * @param string $fieldName
   *   The field name.
   *
   * @return bool
   *   TRUE if the field is defined, FALSE otherwise.
   */
  public function hasField(string $fieldName): bool {
    return isset($this->fields[$fieldName]);
  }

  /**
   * Gets a field definition.
   *
   * @param string $fieldName
   *   The field name.
   *
   * @return \Deuteros\Common\FieldDoubleDefinition|null
   *   The field double definition, or NULL if not defined.
   */
  public function getField(string $fieldName): ?FieldDoubleDefinition {
    return $this->fields[$fieldName] ?? NULL;
  }

  /**
   * Creates a new definition with additional context.
   *
   * The definition is automatically added to context under the reserved
   * `CONTEXT_KEY` ("_definition"), allowing callbacks to access entity
   * metadata.
   *
   * @param array<string, mixed> $additionalContext
   *   Additional context to merge.
   *
   * @return self
   *   A new EntityDoubleDefinition with merged context.
   *
   * @throws \InvalidArgumentException
   *   If additionalContext contains the reserved "_definition" key.
   */
  public function withContext(array $additionalContext): self {
    // Validate reserved key is not used.
    if (array_key_exists(self::CONTEXT_KEY, $additionalContext)) {
      throw new \InvalidArgumentException(sprintf(
        'The context key "%s" is reserved by Deuteros and cannot be used.',
        self::CONTEXT_KEY
      ));
    }

    // Add definition to context only if not already present.
    $definitionContext = array_key_exists(self::CONTEXT_KEY, $this->context)
      ? []
      : [self::CONTEXT_KEY => $this];

    // Merge context: definition first, then existing, then additional.
    $mergedContext = array_merge($definitionContext, $this->context, $additionalContext);

    // Return same instance if context unchanged.
    if ($mergedContext === $this->context) {
      return $this;
    }

    return new self(
      entityType: $this->entityType,
      bundle: $this->bundle,
      id: $this->id,
      uuid: $this->uuid,
      label: $this->label,
      fields: $this->fields,
      interfaces: $this->interfaces,
      methods: $this->methods,
      context: $mergedContext,
      mutable: $this->mutable,
      primaryInterface: $this->primaryInterface,
      lenient: $this->lenient,
      traits: $this->traits,
      url: $this->url,
    );
  }

  /**
   * Creates a new definition with the specified mutability.
   *
   * @param bool $mutable
   *   Whether the entity double should be mutable.
   *
   * @return self
   *   A new EntityDoubleDefinition with the specified mutability.
   */
  public function withMutable(bool $mutable): self {
    if ($this->mutable === $mutable) {
      return $this;
    }
    return new self(
      entityType: $this->entityType,
      bundle: $this->bundle,
      id: $this->id,
      uuid: $this->uuid,
      label: $this->label,
      fields: $this->fields,
      interfaces: $this->interfaces,
      methods: $this->methods,
      context: $this->context,
      mutable: $mutable,
      primaryInterface: $this->primaryInterface,
      lenient: $this->lenient,
      traits: $this->traits,
      url: $this->url,
    );
  }

  /**
   * Gets the interface that declares a specific method.
   *
   * Uses reflection to find which interface in the hierarchy declares
   * the given method. Returns the primary interface first if it declares
   * the method, otherwise searches through all interfaces.
   *
   * @param string $method
   *   The method name.
   *
   * @return string|null
   *   The fully qualified interface name, or NULL if not found.
   */
  public function getDeclaringInterface(string $method): ?string {
    // Check primary interface first if set.
    if ($this->primaryInterface !== NULL) {
      if (method_exists($this->primaryInterface, $method)) {
        // Find the most specific interface that declares this method.
        /** @var \ReflectionClass<object> $reflection */
        $reflection = new \ReflectionClass($this->primaryInterface);
        foreach ($reflection->getInterfaces() as $parent) {
          if ($parent->hasMethod($method)) {
            return $parent->getName();
          }
        }
        return $this->primaryInterface;
      }
    }

    // Search through all declared interfaces.
    foreach ($this->interfaces as $interface) {
      if (method_exists($interface, $method)) {
        return $interface;
      }
    }

    return NULL;
  }

}
