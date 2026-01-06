# Deuteros Usage Guide

## About

**DEUTEROS** (Drupal Entity Unit Test Extensible Replacement Object Scaffolding) is a PHP library that provides value-object entity doubles for Drupal unit testing.

### Problem It Solves

Testing code that depends on Drupal entities typically requires Kernel tests, which are slow because they need:
- Database access
- Module enablement
- Service container initialization

Deuteros lets you create lightweight entity doubles that implement Drupal's entity interfaces, enabling fast unit tests without any of this overhead.

### Key Benefits

- **Fast tests**: No database, no service container, no bootstrapping
- **Pure unit tests**: Test your code in isolation
- **Familiar API**: Works with both PHPUnit native mocks and Prophecy
- **Type-safe**: Full interface compliance with IDE support

---

## Installation

```bash
composer require --dev deuteros-php/Deuteros
```

### Requirements

- PHP 8.3+
- Drupal 10.x or 11.x
- PHPUnit 9.0+/10.0+/11.0+ or Prophecy 1.15+

---

## Quick Start

### Basic Usage

An entity double is instantiated by passing an entity double definition to a factory. The easiest way to define an entity double is using the definition builder:

```php
use Deuteros\Double\EntityDoubleFactory;
use Deuteros\Double\EntityDoubleDefinitionBuilder;

class MyServiceTest extends TestCase {

  public function testMyService(): void {
    // Get a factory (auto-detects PHPUnit or Prophecy)
    $factory = EntityDoubleFactory::fromTest($this);

    // Create an entity double
    $entity = $factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(42)
        ->label('Test Article')
        ->field('field_body', 'Article content here')
        ->build()
    );

    // Use it in your test
    $myService = new MyService();
    $myService->doStuff($entity);
    
    // These assertions would all pass
    $this->assertInstanceOf(EntityInterface::class, $entity);
    $this->assertSame('node', $entity->getEntityTypeId());
    $this->assertSame('article', $entity->bundle());
    $this->assertSame(42, $entity->id());
    $this->assertSame('Article content here', $entity->get('field_body')->value);
  }

}
```

### Minimal Entity

```php
// Just entity type (bundle defaults to entity type)
$entity = $factory->create(
  new EntityDoubleDefinition('node')
);

$this->assertSame('node', $entity->getEntityTypeId());
$this->assertSame('node', $entity->bundle());
```

---

## Builder API Reference

The `EntityDoubleDefinitionBuilder` provides a fluent interface for configuring entity double definitions.

### Factory Methods

| Method | Description                                               |
|--------|-----------------------------------------------------------|
| `create(string $entityType)` | Creates a new builder for the given entity type           |
| `fromInterface(string $entityType, string $interface)` | Creates a new builder from the specified entity interface |
| `from(EntityDoubleDefinition $definition)` | Clones and modifies an existing definition                |

### Metadata Methods

| Method | Description            |
|--------|------------------------|
| `bundle(string\|callable $bundle)` | Sets the entity bundle |
| `id(int\|string\|null\|callable $id)` | Sets the entity ID     |
| `uuid(string\|null\|callable $uuid)` | Sets the entity UUID   |
| `label(string\|null\|callable $label)` | Sets the entity label  |

### Field Methods

| Method | Description                    |
|--------|--------------------------------|
| `field(string $name, mixed $value)` | Adds a single field with value |
| `fields(array $fields)` | Adds multiple fields at once   |

### Interface Methods

| Method | Description                                   |
|--------|-----------------------------------------------|
| `interface(string $interface)` | Adds an interface the double should implement |
| `interfaces(array $interfaces)` | Adds multiple interfaces at once              |

### Method Override Methods

| Method | Description                                   |
|--------|-----------------------------------------------|
| `method(string $name, callable $callback)` | Overrides a method with custom implementation |
| `methods(array $methods)` | Adds multiple method overrides at once        |

### Context Methods

| Method | Description                          |
|--------|--------------------------------------|
| `context(string $key, mixed $value)` | Adds a single context value          |
| `withContext(array $context)` | Adds multiple context values at once |

### Trait Methods

| Method | Description                                    |
|--------|------------------------------------------------|
| `trait(string $traitClassName)` | Adds a trait to apply to the entity double     |
| `traits(array $traitClassNames)` | Adds multiple traits to apply at once          |

### URL Methods

| Method | Description                                    |
|--------|------------------------------------------------|
| `url(string\|callable $url)` | Sets the URL for ::toUrl() method              |

### Other Methods

| Method | Description                                                                 |
|--------|-----------------------------------------------------------------------------|
| `lenient()` | Enables lenient mode (unconfigured methods return null instead of throwing) |
| `build()` | Builds and returns the `EntityDoubleDefinition`                             |

---

## Field Access Patterns

All core field access patterns are supported, which allows to pass the entity double to code manipulating entities.

**Method Access**

```php
// Get field list
$fieldList = $entity->get('field_name');

// Get scalar value (first item's main value)
$value = $entity->get('field_name')->value;

// Get first item
$firstItem = $entity->get('field_name')->first();

// Get item by delta
$item = $entity->get('field_name')->get(0);

// Check if empty
$isEmpty = $entity->get('field_name')->isEmpty();

// Get all values as array of property arrays
$values = $entity->get('field_name')->getValue();
// Returns: [['value' => 'field value']]
```

**Magic Property Access**

```php
// Equivalent to $entity->get('field_name')
$fieldList = $entity->field_name;

// Chain with value access
$value = $entity->field_name->value;
```

**Multi-Value Fields**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_tags', [
      ['target_id' => 1],
      ['target_id' => 2],
      ['target_id' => 3],
    ])
    ->build()
);

// Access by delta
$first = $entity->get('field_tags')->get(0);
$second = $entity->get('field_tags')->get(1);

// Iterate
foreach ($entity->get('field_tags') as $delta => $item) {
  echo $item->target_id;
}

// Shorthand accesses first item
$entity->get('field_tags')->target_id; // Returns 1
```

**Field Item Properties**

```php
// Common properties
$item->value;      // Main value (text, number, etc.)
$item->target_id;  // Entity reference target ID
$item->entity;     // Referenced entity object

// Complex fields store multiple properties
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->field('field_link', [
      'uri' => 'https://example.com',
      'title' => 'Example',
    ])
    ->build()
);

$entity->get('field_link')->uri;   // 'https://example.com'
$entity->get('field_link')->title; // 'Example'
```

### Checking Field Existence

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_title', 'Test')
    ->build()
);

$entity->hasField('field_title');     // true
$entity->hasField('field_nonexistent'); // false
```

Accessing undefined fields throws `InvalidArgumentException`:

```php
$entity->get('undefined_field'); // Throws InvalidArgumentException
```

---

## Advanced Use Cases

### Callback-Based Fields

Use callbacks for dynamic values that depend on test context:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_computed', fn(array $context) => $context['value'] * 2)
    ->build(),
  ['value' => 21] // Context passed to factory
);

$entity->get('field_computed')->value; // Returns 42
```

### Context Propagation

Context flows to all callables (metadata and fields):

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->id(fn(array $context) => $context['id'])
    ->label(fn(array $context) => "Article #{$context['id']}")
    ->field('field_status', fn(array $context) => $context['status'])
    ->build(),
  [
    'id' => 42,
    'status' => 'published',
  ]
);

$entity->id();                        // 42
$entity->label();                     // "Article #42"
$entity->get('field_status')->value;  // "published"
```

### Accessing Definition in Callbacks

All callbacks (for metadata, fields, and method overrides) can access the
entity definition via the reserved `_definition` context key. This enables
callbacks to reference entity properties like `entityType`, `bundle`, `id`,
`uuid`, and other configured values.

**Access via constant:**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->id(42)
    ->label(function (array $context) {
      $def = $context[EntityDoubleDefinition::CONTEXT_KEY];
      return sprintf('Article #%d', $def->id);
    })
    ->build()
);

$entity->label(); // "Article #42"
```

**Note:** The `_definition` key is reserved by Deuteros. Attempting to use this
key in user-provided context will throw an `InvalidArgumentException`.

### Entity References

**Shorthand (Entity Object)**

```php
$author = $factory->create(
  EntityDoubleDefinitionBuilder::create('user')
    ->id(1)
    ->label('admin')
    ->build()
);

$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_author', $author) // Pass entity directly
    ->build()
);

$entity->get('field_author')->entity;    // Returns $author
$entity->get('field_author')->target_id; // Returns 1 (auto-populated)
```

**Explicit Format**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_author', ['entity' => $author])
    ->build()
);
```

**Target ID Only**

Use `['target_id' => X]` when you only need the target ID without an actual entity double. The field will implement `EntityReferenceFieldItemListInterface`, but calling `referencedEntities()` will throw a `LogicException`:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_author', ['target_id' => 42])
    ->build()
);

$entity->get('field_author')->target_id;            // 42
$entity->get('field_author')->entity;               // null (no entity provided)
$entity->get('field_author')->referencedEntities(); // Throws LogicException
```

**Empty Entity References**

Use `['entity' => NULL]` to explicitly declare a field as an entity reference that is empty (no referenced entity). This is useful when you need to test code that checks `referencedEntities()` but the field should be empty:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_author', ['entity' => NULL])
    ->build()
);

// Field implements EntityReferenceFieldItemListInterface
$entity->get('field_author')->referencedEntities(); // Returns []
$entity->get('field_author')->isEmpty();            // Returns true
```

**Multi-Value Entity References**

```php
$tag1 = $factory->create(EntityDoubleDefinitionBuilder::create('taxonomy_term')->id(1)->build());
$tag2 = $factory->create(EntityDoubleDefinitionBuilder::create('taxonomy_term')->id(2)->build());
$tag3 = $factory->create(EntityDoubleDefinitionBuilder::create('taxonomy_term')->id(3)->build());

$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_tags', [$tag1, $tag2, $tag3])
    ->build()
);

// Access individual references
$entity->get('field_tags')->get(0)->entity; // $tag1
$entity->get('field_tags')->get(1)->entity; // $tag2

// Get all referenced entities
$entities = $entity->get('field_tags')->referencedEntities();
// Returns [$tag1, $tag2, $tag3]
```

### Mutable Doubles

By default, entity doubles are immutable. Use `createMutable()` for doubles that can be modified:

```php
$entity = $factory->createMutable(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_status', 'draft')
    ->build()
);

// Initial value
$entity->get('field_status')->value; // 'draft'

// Modify via set()
$entity->set('field_status', 'published');
$entity->get('field_status')->value; // 'published'

// Modify via magic property
$entity->field_status = 'archived';
$entity->get('field_status')->value; // 'archived'

// Chaining works
$entity->set('field_status', 'draft')->set('field_title', 'New Title');
```

Immutable doubles throw on modification attempts:

```php
$entity = $factory->create(/* ... */);
$entity->set('field_status', 'new'); // Throws LogicException
```

### Method Overrides

Override any method with a custom implementation:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->interface(NodeInterface::class)
    ->method('getTitle', fn() => 'Custom Title')
    ->method('isPublished', fn() => true)
    ->method('getCreatedTime', fn() => 1704067200)
    ->build()
);

$entity->getTitle();       // 'Custom Title'
$entity->isPublished();    // true
$entity->getCreatedTime(); // 1704067200
```

Method overrides receive context:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->method('id', fn(array $context) => $context['computed_id'])
    ->build(),
  ['computed_id' => 999]
);

$entity->id(); // 999
```

### URL Support

Configure the entity's `::toUrl()` method to return a Url double:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->id(42)
    ->url('/node/42')
    ->build()
);

// Get the Url double
$url = $entity->toUrl();

// Get the URL string
$url->toString();        // '/node/42'
$url->toString(FALSE);   // '/node/42'

// Get GeneratedUrl with bubbleable metadata
$generatedUrl = $url->toString(TRUE);
$generatedUrl->getGeneratedUrl(); // '/node/42'
```

**Dynamic URLs with Callbacks**

Use callbacks for dynamic URL generation based on context:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->id(fn(array $context) => $context['id'])
    ->url(fn(array $context) => '/node/' . $context['id'])
    ->build(),
  ['id' => 42]
);

$entity->toUrl()->toString(); // '/node/42'
```

### Interface Composition

**Adding Multiple Interfaces**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->interface(FieldableEntityInterface::class)
    ->interface(EntityChangedInterface::class)
    ->method('getChangedTime', fn() => 1704067200)
    ->build()
);

$this->assertInstanceOf(EntityChangedInterface::class, $entity);
$entity->getChangedTime(); // 1704067200
```

**Defining a double from an interface hierarchy**

The `::fromInterface` method auto-discovers the interface hierarchy:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
    ->bundle('article')
    ->id(42)
    ->method('getTitle', fn() => 'My Article')
    ->method('isPublished', fn() => true)
    ->build()
);

// Entity implements NodeInterface and all its parent interfaces
$this->assertInstanceOf(NodeInterface::class, $entity);
$this->assertInstanceOf(ContentEntityInterface::class, $entity);
```

### Lenient Mode

Lenient mode returns `null` for unconfigured methods instead of throwing:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
    ->bundle('article')
    ->lenient() // Enable lenient mode
    ->build()
);

// Unconfigured methods return null
$entity->save();   // Returns null (normally throws)
$entity->delete(); // Returns null (normally throws)
```

Without lenient mode:

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
    ->bundle('article')
    ->build()
);

$entity->save(); // Throws LogicException
```

### Applying Traits to Entity Doubles

Entity classes often use traits to encapsulate business logic. This includes bundle classes, custom entity classes extending `EntityBase`, and more. Deuteros allows you to apply traits to entity doubles, enabling you to unit test trait implementations that depend on entity interface methods.

When traits are specified, the factory generates a stub class that extends the entity double and uses the traits. The trait methods can then call the mocked entity methods (`get()`, `id()`, `label()`, etc.) and receive the configured values.

**Single Trait**

```php
trait ArticleTrait {
  public function getByLine(): string {
    return $this->get('field_byline')->value;
  }
}

$article = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_byline', 'John Doe')
    ->trait(ArticleTrait::class)
    ->build()
);

$article->getByLine(); // 'John Doe'
```

**Multiple Traits**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->id(21)
    ->trait(ArticleTrait::class)
    ->trait(PublishableTrait::class)
    ->build()
);

// Or use the traits() method
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->traits([ArticleTrait::class, PublishableTrait::class])
    ->build()
);
```

**Traits with Mutable Doubles**

Traits work with both immutable and mutable entity doubles:

```php
$entity = $factory->createMutable(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->field('field_status', 'draft')
    ->trait(StatusTrait::class)
    ->build()
);

// Trait methods work with initial values
$entity->getStatus(); // 'draft'

// Mutations are reflected in trait method calls
$entity->set('field_status', 'published');
// Next call to trait method sees updated value
```

**Testing Bundle Classes**

This is particularly useful for testing Drupal bundle classes that use traits:

```php
// Drupal bundle class pattern
interface ArticleInterface extends NodeInterface {
  public function getByLine(): string;
}

final class Article extends Node implements ArticleInterface {
  use ArticleTrait;
}

trait ArticleTrait {
  public function getByLine(): string {
    return $this->get('field_byline')->value;
  }
}

// Test the trait in isolation
class ArticleTraitTest extends TestCase {
  public function testGetByLine(): void {
    $factory = EntityDoubleFactory::fromTest($this);

    $article = $factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->interface(ArticleInterface::class)
        ->trait(ArticleTrait::class)
        ->field('field_byline', 'Jane Smith')
        ->build()
    );

    $this->assertSame('Jane Smith', $article->getByLine());
  }
}
```

---

## Unsupported Operations

Entity doubles are lightweight value objects. Operations requiring runtime services throw `LogicException` with helpful error messages:

**Storage Operations**

- `save()` - Requires entity storage service
- `delete()` - Requires entity storage service

**Service-Dependent Operations**

- `access()` - Requires access control handler
- `toLink()` - Requires link generator service

**Field Definition Operations**

- `getFieldDefinition()` - Requires entity field manager
- `getFieldDefinitions()` - Requires entity field manager

**Revision Operations**

- `isDefaultRevision()` - Requires revision tracking
- `isLatestRevision()` - Requires revision tracking

**Lifecycle Hooks**

- `preSave()`, `postSave()`, `preCreate()`, `postCreate()`, etc.

### Handling Unsupported Operations

**Option 1: Method Override**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->method('save', fn() => 1) // Return entity ID
    ->build()
);
```

**Option 2: Lenient Mode**

```php
$entity = $factory->create(
  EntityDoubleDefinitionBuilder::create('node')
    ->bundle('article')
    ->lenient()
    ->build()
);

$entity->save(); // Returns null instead of throwing
```

---

## Framework-Specific Notes

### Auto-Detection

`EntityDoubleFactory::fromTest($this)` automatically detects your test framework:

```php
// Works with both PHPUnit and Prophecy
$factory = EntityDoubleFactory::fromTest($this);
```

### Explicit Factory Selection

```php
use Deuteros\Double\PhpUnit\MockEntityDoubleFactory;
use Deuteros\Double\Prophecy\ProphecyEntityDoubleFactory;

// PHPUnit native mocks
$factory = MockEntityDoubleFactory::fromTest($this);

// Prophecy doubles
$factory = ProphecyEntityDoubleFactory::fromTest($this);
```

### Behavioral Parity

Both PHPUnit and Prophecy adapters behave identically. You can switch between them without changing your test logic.

---

## Complete Example

```php
<?php

namespace Drupal\my_module\Tests\Unit;

use Deuteros\Double\EntityDoubleFactory;
use Deuteros\Double\EntityDoubleDefinitionBuilder;
use Drupal\my_module\Service\ArticleProcessor;
use PHPUnit\Framework\TestCase;

class ArticleProcessorTest extends TestCase {

  public function testProcessArticle(): void {
    $factory = EntityDoubleFactory::fromTest($this);

    // Create author
    $author = $factory->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(1)
        ->label('John Doe')
        ->build()
    );

    // Create article with all the fields we need
    $article = $factory->create(
      EntityDoubleDefinitionBuilder::create('node')
        ->bundle('article')
        ->id(42)
        ->uuid('550e8400-e29b-41d4-a716-446655440000')
        ->label('Test Article')
        ->field('field_body', 'Article content goes here.')
        ->field('field_author', $author)
        ->field('field_tags', [
          ['target_id' => 1],
          ['target_id' => 2],
        ])
        ->build()
    );

    // Test your service
    $processor = new ArticleProcessor();
    $result = $processor->process($article);

    $this->assertSame('processed', $result['status']);
    $this->assertSame('John Doe', $result['author_name']);
  }

}
```

---

## Testing Entity Objects

While entity doubles are the primary focus of Deuteros, sometimes you need to
test code that specifically requires a real entity class instance (e.g.,
`Node`, `User`, or custom entity classes). The `SubjectEntityFactory` provides
this capability by instantiating actual Drupal entity classes with doubled
service dependencies and DEUTEROS field doubles.

The term "subject" refers to the entity class being tested, as opposed to
"doubles" which are test substitutes for dependencies.

### When to Use SubjectEntityFactory

Use `SubjectEntityFactory` when you need to:
- Test code that checks `instanceof` against concrete entity classes
- Test entity class methods that are not defined on interfaces
- Test bundle classes or entity-specific traits
- Verify behavior that depends on entity class inheritance

For most testing scenarios, entity doubles (via `EntityDoubleFactory`) are
preferred as they're lighter weight and framework-agnostic.

### Basic Usage

```php
class MyNodeTest extends TestCase {

  private SubjectEntityFactory $factory;

  protected function setUp(): void {
    $this->factory = SubjectEntityFactory::fromTest($this);
    $this->factory->installContainer();
  }

  protected function tearDown(): void {
    $this->factory->uninstallContainer();
  }

  public function testNodeCreation(): void {
    $node = $this->factory->create(Node::class, [
      'nid' => 42,
      'type' => 'article',
      'title' => 'Test Article',
      'body' => ['value' => 'Body text', 'format' => 'basic_html'],
      'status' => 1,
    ]);

    // Real Node instance with DEUTEROS field doubles.
    $this->assertInstanceOf(Node::class, $node);
    $this->assertSame('node', $node->getEntityTypeId());
    $this->assertSame('article', $node->bundle());
    $this->assertSame('Test Article', $node->get('title')->value);
    $this->assertSame('Body text', $node->get('body')->value);
  }

}
```

### Entity Reference Fields

You can use DEUTEROS doubles as entity reference targets:

```php
public function testWithEntityReference(): void {
  // Create author double using the factory.
  $author = $this->factory->getDoubleFactory()->create(
    EntityDoubleDefinitionBuilder::create('user')
      ->id(42)
      ->label('Jane Doe')
      ->build()
  );

  $node = $this->factory->create(Node::class, [
    'nid' => 1,
    'type' => 'article',
    'title' => 'Test',
    'uid' => $author,  // Entity double as reference.
  ]);

  $this->assertSame($author, $node->get('uid')->entity);
  $this->assertEquals(42, $node->get('uid')->target_id);
}
```

### Multi-Value Fields

```php
public function testMultiValueField(): void {
  $node = $this->factory->create(Node::class, [
    'nid' => 1,
    'type' => 'article',
    'title' => 'Test',
    'field_tags' => [
      ['target_id' => 1],
      ['target_id' => 2],
      ['target_id' => 3],
    ],
  ]);

  $this->assertSame(1, $node->get('field_tags')->get(0)->target_id);
  $this->assertSame(2, $node->get('field_tags')->get(1)->target_id);
  $this->assertSame(3, $node->get('field_tags')->get(2)->target_id);
}
```

### Auto-Detection

Like `EntityDoubleFactory`, `SubjectEntityFactory::fromTest()` auto-detects
whether your test uses PHPUnit or Prophecy:

```php
// Works with both PHPUnit and Prophecy
$factory = SubjectEntityFactory::fromTest($this);
```

### Container Lifecycle

The factory installs a Symfony container with doubled services:

```php
protected function setUp(): void {
  $this->factory = SubjectEntityFactory::fromTest($this);
  $this->factory->installContainer(); // Required before create()
}

protected function tearDown(): void {
  $this->factory->uninstallContainer(); // Cleanup
}
```

Calling `installContainer()` twice throws `LogicException`. Calling
`uninstallContainer()` multiple times is safe (idempotent).

### Using SubjectEntityTestBase

For simpler test setup, extend `SubjectEntityTestBase` which handles factory
setup and teardown automatically:

```php
use Deuteros\Entity\SubjectEntityTestBase;
use Drupal\node\Entity\Node;

class MyNodeTest extends SubjectEntityTestBase {

  public function testNodeCreation(): void {
    $node = $this->createEntity(Node::class, [
      'nid' => 1,
      'type' => 'article',
      'title' => 'Test Article',
    ]);

    $this->assertInstanceOf(Node::class, $node);
    $this->assertSame('Test Article', $node->get('title')->value);
  }

  public function testWithEntityReference(): void {
    $author = $this->getDoubleFactory()->create(
      EntityDoubleDefinitionBuilder::create('user')
        ->id(42)
        ->label('Jane Doe')
        ->build()
    );

    $node = $this->createEntity(Node::class, [
      'nid' => 1,
      'type' => 'article',
      'title' => 'Test',
      'uid' => $author,
    ]);

    $this->assertSame($author, $node->get('uid')->entity);
  }

}
```

**Available Properties and Methods:**

| Member | Description |
|--------|-------------|
| `$this->subjectEntityFactory` | The underlying `SubjectEntityFactory` instance |
| `$this->createEntity($class, $values)` | Convenience method for creating entities |
| `$this->createEntityWithId($class, $values)` | Create entities with auto-incremented IDs |
| `$this->getDoubleFactory()` | Get the entity double factory for references |

### Auto-Incremented IDs

When testing scenarios that involve multiple entities, you can use
`createWithId()` (or `createEntityWithId()` on `SubjectEntityTestBase`) to
automatically assign sequential integer IDs. IDs are tracked separately per
entity type:

```php
class MyNodeTest extends SubjectEntityTestBase {

  public function testMultipleNodes(): void {
    // IDs are auto-incremented: 1, 2, 3
    $node1 = $this->createEntityWithId(Node::class, [
      'type' => 'article',
      'title' => 'First Article',
    ]);
    $node2 = $this->createEntityWithId(Node::class, [
      'type' => 'article',
      'title' => 'Second Article',
    ]);
    $node3 = $this->createEntityWithId(Node::class, [
      'type' => 'page',
      'title' => 'A Page',
    ]);

    $this->assertSame(1, $node1->id());
    $this->assertSame(2, $node2->id());
    $this->assertSame(3, $node3->id());
  }

  public function testDifferentEntityTypes(): void {
    // Each entity type has its own ID sequence.
    $node = $this->createEntityWithId(Node::class, [
      'type' => 'article',
      'title' => 'An Article',
    ]);
    $user = $this->createEntityWithId(User::class, [
      'name' => 'testuser',
    ]);

    // Both get ID 1 (separate counters).
    $this->assertSame(1, $node->id());
    $this->assertSame(1, $user->id());
  }

}
```

This is useful when emulating loading existing entities and you don't need
specific ID values. The counters reset automatically via `uninstallContainer()`,
so each test method starts fresh.

### Config Entities

`SubjectEntityFactory` also supports config entities. Config entities do not
have field doubles since they don't implement `FieldableEntityInterface`:

```php
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Config\Entity\ConfigEntityBase;

#[ConfigEntityType(
  id: 'my_config',
  label: new TranslatableMarkup('My Config'),
  entity_keys: ['id' => 'id', 'label' => 'label'],
)]
class MyConfigEntity extends ConfigEntityBase {
  protected ?string $id = NULL;
  protected ?string $label = NULL;
  public ?string $description = NULL;
}

// In your test:
$config = $this->createEntity(MyConfigEntity::class, [
  'id' => 'my_config_id',
  'label' => 'My Configuration',
  'description' => 'A description',
]);

$this->assertSame('my_config_id', $config->id());
$this->assertSame('My Configuration', $config->label());
$this->assertSame('A description', $config->description);
```

### Class Hierarchy Support

`SubjectEntityFactory` automatically traverses the class hierarchy when looking
for `#[ContentEntityType]` or `#[ConfigEntityType]` attributes. This means you
can create subclasses of entity classes that have the attribute without needing
to redeclare it:

```php
// Parent class has the attribute
#[ContentEntityType(id: 'node', ...)]
class Node extends ContentEntityBase { }

// Child class inherits the attribute - no need to redeclare it
class CustomNode extends Node {
  // Custom methods or bundle-specific behavior
}

// Both work with SubjectEntityFactory
$node = $factory->create(Node::class, ['nid' => 1, 'type' => 'article']);
$customNode = $factory->create(CustomNode::class, ['nid' => 2, 'type' => 'article']);
```

This is particularly useful for testing custom bundle classes that extend core
entity classes like `Node` or `User`.

### Limitations

Entity objects created by `SubjectEntityFactory` have these limitations:

| Operation | Status | Notes |
|-----------|--------|-------|
| `id()`, `bundle()`, `getEntityTypeId()` | Works | Set via entity keys |
| `get($field)`, `$entity->field` | Works | Returns DEUTEROS field doubles (content entities only) |
| `save()`, `delete()` | Throws | No storage backend |
| Entity queries | Not supported | No database |
| `access()` | Throws | No access control handler |
| Full translation workflows | Limited | Basic language support only |

For operations requiring full Drupal services, use Kernel tests instead.
