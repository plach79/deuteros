<?php

declare(strict_types=1);

namespace Deuteros\Common;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Fluent builder for creating "EntityDoubleDefinition" instances.
 *
 * Provides a type-safe, discoverable API for configuring entity doubles
 * without relying on array keys. Auto-adds "FieldableEntityInterface" when
 * fields are defined.
 *
 * @example Basic usage
 * ```php
 * $definition = EntityDoubleDefinitionBuilder::create('node')
 *   ->bundle('article')
 *   ->id(42)
 *   ->build();
 * ```
 *
 * @example With fields (auto-adds FieldableEntityInterface)
 * ```php
 * $definition = EntityDoubleDefinitionBuilder::create('node')
 *   ->bundle('article')
 *   ->field('field_title', 'Test Title')
 *   ->field('field_tags', [['target_id' => 1], ['target_id' => 2]])
 *   ->build();
 * ```
 *
 * @example Initialize from existing definition
 * ```php
 * $modified = EntityDoubleDefinitionBuilder::from($existingDefinition)
 *   ->label('New Label')
 *   ->build();
 * ```
 */
final class EntityDoubleDefinitionBuilder {

  /**
   * The entity type ID.
   */
  private string $entityType;

  /**
   * The bundle (defaults to entity type if empty).
   */
  private string $bundle = '';

  /**
   * The entity ID.
   */
  private mixed $id = NULL;

  /**
   * The entity UUID.
   */
  private mixed $uuid = NULL;

  /**
   * The entity label.
   */
  private mixed $label = NULL;

  /**
   * Field definitions keyed by field name.
   *
   * @var array<string, \Deuteros\Common\FieldDoubleDefinition>
   */
  private array $fields = [];

  /**
   * Interfaces to implement.
   *
   * @var list<class-string>
   */
  private array $interfaces = [];

  /**
   * Method overrides keyed by method name.
   *
   * @var array<string, callable|mixed>
   */
  private array $methods = [];

  /**
   * Context data for callback resolution.
   *
   * @var array<string, mixed>
   */
  private array $context = [];

  /**
   * The primary interface for improved error messages.
   *
   * @var class-string|null
   */
  private ?string $primaryInterface = NULL;

  /**
   * Whether to use lenient mode.
   */
  private bool $lenient = FALSE;

  /**
   * Traits to apply to the entity double.
   *
   * @var list<class-string>
   */
  private array $traits = [];

  /**
   * The URL for ::toUrl (scalar or callable).
   */
  private mixed $url = NULL;

  /**
   * Private constructor - use create(), from(), or fromInterface() methods.
   *
   * @param string $entityType
   *   The entity type ID.
   */
  private function __construct(string $entityType) {
    $this->entityType = $entityType;
  }

  /**
   * Creates a new builder for the given entity type.
   *
   * @param string $entityType
   *   The entity type ID (e.g., 'node', 'user', 'taxonomy_term').
   *
   * @return self
   *   A new builder instance.
   */
  public static function create(string $entityType): self {
    return new self($entityType);
  }

  /**
   * Creates a builder from an interface, auto-detecting the hierarchy.
   *
   * Uses reflection to discover all interfaces extended by the given
   * interface and automatically adds them to the builder. Also stores
   * the primary interface for improved error messages.
   *
   * @param string $entityType
   *   The entity type ID (e.g., 'node', 'user', 'taxonomy_term').
   * @param class-string $interface
   *   The primary interface (must extend "EntityInterface").
   *
   * @return self
   *   A new builder instance with all interfaces from the hierarchy.
   *
   * @throws \InvalidArgumentException
   *   If the interface does not exist or does not extend "EntityInterface".
   */
  public static function fromInterface(string $entityType, string $interface): self {
    // Validate interface exists.
    if (!interface_exists($interface)) {
      throw new \InvalidArgumentException(sprintf(
        "Interface '%s' does not exist.",
        $interface
      ));
    }

    // Validate it extends EntityInterface.
    if (!is_a($interface, EntityInterface::class, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        "Interface '%s' must extend EntityInterface.",
        $interface
      ));
    }

    $builder = new self($entityType);
    $builder->primaryInterface = $interface;

    // Add the primary interface.
    $builder->interface($interface);

    // Use reflection to get all parent interfaces.
    $reflection = new \ReflectionClass($interface);
    foreach ($reflection->getInterfaces() as $parentInterface) {
      $builder->interface($parentInterface->getName());
    }

    return $builder;
  }

  /**
   * Creates a builder initialized from an existing definition.
   *
   * Allows copying and modifying existing definitions.
   *
   * @param \Deuteros\Common\EntityDoubleDefinition $definition
   *   The existing definition to copy from.
   *
   * @return self
   *   A new builder instance with values from the definition.
   */
  public static function from(EntityDoubleDefinition $definition): self {
    $builder = new self($definition->entityType);
    $builder->bundle = $definition->bundle;
    $builder->id = $definition->id;
    $builder->uuid = $definition->uuid;
    $builder->label = $definition->label;
    $builder->fields = $definition->fields;
    $builder->interfaces = $definition->interfaces;
    $builder->methods = $definition->methods;
    $builder->context = $definition->context;
    $builder->primaryInterface = $definition->primaryInterface;
    $builder->lenient = $definition->lenient;
    $builder->traits = $definition->traits;
    $builder->url = $definition->url;
    return $builder;
  }

  /**
   * Sets the bundle.
   *
   * @param string $bundle
   *   The bundle name (defaults to entity type if not set).
   *
   * @return $this
   */
  public function bundle(string $bundle): self {
    $this->bundle = $bundle;
    return $this;
  }

  /**
   * Sets the entity ID.
   *
   * @param mixed $id
   *   The entity ID (scalar or callable).
   *
   * @return $this
   */
  public function id(mixed $id): self {
    $this->id = $id;
    return $this;
  }

  /**
   * Sets the entity UUID.
   *
   * @param mixed $uuid
   *   The entity UUID (scalar or callable).
   *
   * @return $this
   */
  public function uuid(mixed $uuid): self {
    $this->uuid = $uuid;
    return $this;
  }

  /**
   * Sets the entity label.
   *
   * @param mixed $label
   *   The entity label (scalar or callable).
   *
   * @return $this
   */
  public function label(mixed $label): self {
    $this->label = $label;
    return $this;
  }

  /**
   * Adds a field with the given value.
   *
   * Automatically adds FieldableEntityInterface if not already present.
   *
   * @param string $fieldName
   *   The field name.
   * @param mixed $value
   *   The field value (scalar, array, or callable).
   *
   * @return $this
   */
  public function field(string $fieldName, mixed $value): self {
    $this->fields[$fieldName] = $value instanceof FieldDoubleDefinition
      ? $value
      : new FieldDoubleDefinition($value);
    return $this;
  }

  /**
   * Adds multiple fields at once.
   *
   * Automatically adds FieldableEntityInterface if not already present.
   *
   * @param array<string, mixed> $fields
   *   Field values keyed by field name.
   *
   * @return $this
   */
  public function fields(array $fields): self {
    foreach ($fields as $fieldName => $value) {
      $this->field($fieldName, $value);
    }
    return $this;
  }

  /**
   * Adds an interface to implement.
   *
   * Note: "FieldableEntityInterface" is auto-added when fields are defined.
   * "EntityInterface" is always included by the factory.
   *
   * @param class-string $interface
   *   The fully qualified interface name.
   *
   * @return $this
   */
  public function interface(string $interface): self {
    if (!in_array($interface, $this->interfaces, TRUE)) {
      $this->interfaces[] = $interface;
    }
    return $this;
  }

  /**
   * Adds multiple interfaces to implement.
   *
   * @param list<class-string> $interfaces
   *   List of fully qualified interface names.
   *
   * @return $this
   */
  public function interfaces(array $interfaces): self {
    foreach ($interfaces as $interface) {
      $this->interface($interface);
    }
    return $this;
  }

  /**
   * Adds a method override.
   *
   * Method overrides take precedence over core resolvers.
   *
   * @param string $method
   *   The method name.
   * @param callable|mixed $resolver
   *   The resolver (callable receiving context array, or static value).
   *
   * @return $this
   */
  public function method(string $method, mixed $resolver): self {
    $this->methods[$method] = $resolver;
    return $this;
  }

  /**
   * Adds multiple method overrides at once.
   *
   * @param array<string, callable|mixed> $overrides
   *   Overrides keyed by method name.
   *
   * @return $this
   */
  public function methods(array $overrides): self {
    foreach ($overrides as $method => $resolver) {
      $this->method($method, $resolver);
    }
    return $this;
  }

  /**
   * Adds a single context value.
   *
   * Note: Context can also be passed at factory create time, which will
   * be merged with any context set here.
   *
   * @param string $key
   *   The context key.
   * @param mixed $value
   *   The context value.
   *
   * @return $this
   */
  public function context(string $key, mixed $value): self {
    $this->context[$key] = $value;
    return $this;
  }

  /**
   * Adds multiple context values at once.
   *
   * @param array<string, mixed> $context
   *   Context values keyed by name.
   *
   * @return $this
   */
  public function withContext(array $context): self {
    $this->context = array_merge($this->context, $context);
    return $this;
  }

  /**
   * Enables or disables lenient mode.
   *
   * In lenient mode, unconfigured methods return null instead of throwing
   * exceptions. This includes both regular methods and explicitly unsupported
   * methods (save, delete, etc.).
   *
   * @param bool $lenient
   *   Whether to enable lenient mode. Defaults to TRUE.
   *
   * @return $this
   */
  public function lenient(bool $lenient = TRUE): self {
    $this->lenient = $lenient;
    return $this;
  }

  /**
   * Adds a trait to apply to the entity double.
   *
   * When traits are specified, the factory generates a stub class that extends
   * the entity double and uses the traits. This enables unit testing of trait
   * implementations that depend on entity interface methods.
   *
   * @param class-string $traitClassName
   *   The fully-qualified trait class name.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   If the trait does not exist.
   */
  public function trait(string $traitClassName): self {
    if (!trait_exists($traitClassName)) {
      throw new \InvalidArgumentException(sprintf(
        "Trait '%s' does not exist.",
        $traitClassName
      ));
    }
    if (!in_array($traitClassName, $this->traits, TRUE)) {
      $this->traits[] = $traitClassName;
    }
    return $this;
  }

  /**
   * Adds multiple traits to apply to the entity double.
   *
   * @param list<class-string> $traitClassNames
   *   List of fully-qualified trait class names.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   If any trait does not exist.
   */
  public function traits(array $traitClassNames): self {
    foreach ($traitClassNames as $traitClassName) {
      $this->trait($traitClassName);
    }
    return $this;
  }

  /**
   * Sets the URL for ::toUrl.
   *
   * When configured, the entity double's ::toUrl method will return a Url
   * double. The Url double's ::toString method returns the specified URL
   * string, and ::toString(TRUE) returns a GeneratedUrl double.
   *
   * @param mixed $url
   *   The URL string (scalar or callable receiving context array).
   *
   * @return $this
   */
  public function url(mixed $url): self {
    $this->url = $url;
    return $this;
  }

  /**
   * Builds the EntityDoubleDefinition.
   *
   * @return \Deuteros\Common\EntityDoubleDefinition
   *   The built entity double definition.
   *
   * @throws \InvalidArgumentException
   *   If the configuration is invalid.
   */
  public function build(): EntityDoubleDefinition {
    $interfaces = $this->interfaces;

    // Auto-add "FieldableEntityInterface" when fields are defined.
    if ($this->fields !== [] && !in_array(FieldableEntityInterface::class, $interfaces, TRUE)) {
      $interfaces[] = FieldableEntityInterface::class;
    }

    return new EntityDoubleDefinition(
      entityType: $this->entityType,
      bundle: $this->bundle,
      id: $this->id,
      uuid: $this->uuid,
      label: $this->label,
      fields: $this->fields,
      interfaces: $interfaces,
      methods: $this->methods,
      context: $this->context,
      primaryInterface: $this->primaryInterface,
      lenient: $this->lenient,
      traits: $this->traits,
      url: $this->url,
    );
  }

}
