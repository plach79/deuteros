# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**DEUTEROS** (Drupal Entity Unit Test Extensible Replacement Object Scaffolding) is a PHP library providing value-object entity doubles for Drupal unit testing. It allows testing code that depends on entity/field interfaces without Kernel tests, module enablement, database access, or service container.

- **Composer package:** `plach79/deuteros`
- **Root namespace:** `\Deuteros`
- **PHP version:** 8.3+
- **Drupal compatibility:** 10.x, 11.x
- **Test frameworks:** PHPUnit 9.0+/10.0+/11.0+, Prophecy 1.15+

## Build & Test Commands

This project uses two Composer configurations:
- `composer.dev.json` - Development (includes Drupal core, phpcs, phpstan)
- `composer.json` - Production (uses interface stubs, minimal dependencies)

```bash
# Development setup (recommended for working on the package)
COMPOSER=composer.dev.json composer install

# Run tests
composer test                 # Run all tests (alias for phpunit)
./vendor/bin/phpunit          # Run all tests directly
./vendor/bin/phpunit tests/Unit        # Unit tests only
./vendor/bin/phpunit tests/Integration # Integration tests only
./vendor/bin/phpunit --filter TestName # Run specific test by name

# Production setup (for testing stub compatibility)
composer install              # Uses stubs instead of Drupal core
```

## Coding Standards

The codebase follows Drupal coding standards (Drupal + DrupalPractice sniffs).

```bash
# Requires development setup (COMPOSER=composer.dev.json composer install)
COMPOSER=composer.dev.json composer phpcs       # Check coding standards
COMPOSER=composer.dev.json composer phpcbf      # Auto-fix coding standard violations
```

Key requirements enforced by phpcs:
- 2-space indentation
- Opening braces on same line as class/function declarations
- `@return` descriptions required in docblocks
- Parentheses required for anonymous class constructors (`new class ()`)
- No empty doc comments

Additional formatting rules:
- Method/function signatures should be on a single line if ≤160 characters
- Constructors are exempt (they use property promotion and can span multiple lines)

PHPDoc description text formatting:
- Comment/PHPDocs line length max 80 characters
- Method names should be prefixed with `::` without parentheses (e.g., `::fromTest`)
- Non-fully-qualified class/interface/trait names should be double-quoted (e.g., `"EntityInterface"`)
- String literals should be double-quoted (e.g. `"taxonomy_term"`)
- Fully-qualified names with backslash prefix don't need quotes (e.g. `\Drupal\Core\Entity\EntityInterface`)
- These rules apply to description text only, not `@param`/`@return` type hints

## Static Analysis

PHPStan is configured at level 10 (max) with zero baseline errors.

```bash
# Requires development setup (COMPOSER=composer.dev.json composer install)
COMPOSER=composer.dev.json  composer phpstan    # Run static analysis
```

Configuration files:
- `phpstan.neon` - Main configuration

## Continuous Integration

GitHub Actions runs quality checks on every PR and push to main:

- **Dev Quality Checks**: Installs dev dependencies, runs phpcs, phpstan, and tests
- **Production Test**: Installs production dependencies (with stubs), runs tests

Workflow file: `.github/workflows/ci.yml`

## Architecture

### Layer Structure

1. **Definition Layer** (`Deuteros\Common\EntityDoubleDefinition`, `FieldDoubleDefinition`)
   - Immutable value objects storing entity double metadata and field values
   - Pure PHP, no Drupal dependencies

2. **Core Resolution Layer** (`Deuteros\Common\*DoubleBuilder`)
   - `EntityDoubleBuilder` - Resolvers for entity methods (id, uuid, bundle, toUrl, etc.)
   - `FieldItemListDoubleBuilder` - Resolvers for field lists (first, get, getValue)
   - `FieldItemDoubleBuilder` - Resolvers for field items
   - `UrlDoubleBuilder` - Resolvers for Url doubles (toString)
   - Framework-agnostic: no PHPUnit/Prophecy references

3. **Shared Support**
   - `MutableStateContainer` - Stateful storage for mutable field values
   - `GuardrailEnforcer` - Centralized exception throwing for unsupported methods
   - `EntityReferenceNormalizer` - Normalizes entity reference field values

4. **Factory Classes**
   - `Deuteros\Common\EntityDoubleFactory` - Abstract base with `fromTest()` factory
   - `Deuteros\PhpUnit\MockEntityDoubleFactory` - PHPUnit native mocks
   - `Deuteros\Prophecy\ProphecyEntityDoubleFactory` - Prophecy doubles

### Key Patterns

**Resolver Pattern:** All builders produce `callable` resolvers with signature:
```php
fn(array $context, ...$args): mixed
```

**Method Resolution Order:**
1. `method` overrides (highest precedence)
2. Core resolvers from builders
3. Guardrail failure (throws with differentiated message)

**Field List Caching:** `$entity->field_name` always returns the same `FieldItemListInterface` double per entity instance.

**Immutable vs Mutable:**
- Immutable doubles (default): Throw on field mutation
- Mutable doubles: Track changes in `MutableStateContainer` for assertions
- Metadata (id, uuid, entityType, bundle) always immutable

**Definition in Context:** All resolver callbacks receive the
`EntityDoubleDefinition` in context via `$context[EntityDoubleDefinition::CONTEXT_KEY]`
(key: `_definition`). This enables callbacks to access entity metadata like
entityType, bundle, id, uuid, etc. The `_definition` key is reserved and cannot
be used by user-provided context.

**Trait Stub Generation:**
- When traits are specified via `trait()` or `traits()` builder methods, the factory
  generates a stub class that extends the entity double and uses the traits
- Stub classes are generated via `eval()` and cached statically for performance
- The stub's internal state is copied from the original double (adapter-specific)
- PHPUnit: All mock properties are copied via reflection
- Prophecy: The `objectProphecyClosure` property is copied to maintain prophecy binding

## PHP 8.3 Features Used

The codebase leverages modern PHP features:

- **Readonly classes** (PHP 8.2): `EntityDoubleDefinition`, `FieldDoubleDefinition` are `final readonly class`
- **Constructor property promotion**: Used throughout for cleaner constructors
- **Typed class constants** (PHP 8.3): `GuardrailEnforcer::UNSUPPORTED_METHODS` uses `const array`
- **Match expressions**: Used in `resolveValue()` and `normalizeToArray()` methods
- **Readonly properties**: Builder classes use `private readonly` for immutable dependencies

## Non-Negotiable Constraints

These constraints must never be violated:

- **No concrete Drupal classes** - Interfaces only
- **No service container access**
- **No database access**
- **Entities are value objects** - Read-only unless explicitly mutable
- **Unsupported operations fail loudly** with differentiated error messages
- **PHPUnit and Prophecy adapters must behave identically**
- **Use term "Double"** everywhere except when referring to PHPUnit mock objects
- **All code must pass `composer phpcs`** - Run before completing any code change
- **All code must pass `composer phpstan`** - Run before completing any code change

## Test Structure

- `tests/Unit/Common/` - Unit tests for definition, support, and builder classes
  - Definition layer: `EntityDoubleDefinitionTest`, `FieldDoubleDefinitionTest`,
    `EntityDoubleDefinitionBuilderTest`
  - Core resolution layer: `EntityDoubleBuilderTest`, `FieldItemListDoubleBuilderTest`,
    `FieldItemDoubleBuilderTest`
  - Support: `MutableStateContainerTest`, `GuardrailEnforcerTest`,
    `EntityReferenceNormalizerTest`
- `tests/Integration/PhpUnit/` - PHPUnit factory integration tests
- `tests/Integration/Prophecy/` - Prophecy factory integration tests
- `tests/Integration/EntityDoubleFactoryTestBase.php` - Shared tests inherited by
  both adapter test classes (parity verified via inheritance)
- `tests/Fixtures/` - Test fixtures including test traits (`TestBundleTrait`,
  `SecondTestTrait`) for trait support tests
- `tests/Performance/` - Benchmarking tests comparing performance approaches

## Directory Layout

After running `COMPOSER=composer.dev.json composer install`:
- `src/` contains the library codebase
- `stubs/` contains interface stubs (used when Drupal core is not available)
- `tests` contains test code
- `vendor` contains all Composer dependencies
- `web/core` contains Drupal core (only in development mode)

## Documentation

- `docs/USAGE.md` - User guide and API reference
- `docs/ARCHITECTURE.md` - Architectural documentation for contributors
- `docs/todo.md` - To Do list
- `docs/archive/` - Historical implementation documents (init.md, plan.md, refactoring.md)
