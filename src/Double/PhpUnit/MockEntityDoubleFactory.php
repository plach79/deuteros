<?php

declare(strict_types=1);

namespace Deuteros\Double\PhpUnit;

use Deuteros\Double\EntityDoubleDefinition;
use Deuteros\Double\EntityDoubleBuilder;
use Deuteros\Double\EntityDoubleFactory;
use Deuteros\Double\EntityDoubleFactoryInterface;
use Deuteros\Double\FieldItemDoubleBuilder;
use Deuteros\Double\FieldItemListDoubleBuilder;
use Deuteros\Double\GuardrailEnforcer;
use Deuteros\Double\UrlDoubleBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use PHPUnit\Framework\TestCase;

/**
 * Factory for creating entity doubles using PHPUnit native mock objects.
 */
final class MockEntityDoubleFactory extends EntityDoubleFactory {

  /**
   * Constructs a MockEntityDoubleFactory.
   *
   * @param \PHPUnit\Framework\TestCase $testCase
   *   The PHPUnit test case instance.
   */
  public function __construct(
    private readonly TestCase $testCase,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return static
   */
  public static function fromTest(TestCase $test): EntityDoubleFactoryInterface {
    return new MockEntityDoubleFactory($test);
  }

  /**
   * {@inheritdoc}
   */
  protected function createDoubleForInterfaces(array $interfaces): object {
    // Use runtime interface for ::__get/::__set support.
    $runtimeInterface = $this->getOrCreateRuntimeInterface($interfaces);
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', $runtimeInterface);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function wireEntityResolvers(object $double, EntityDoubleBuilder $builder, EntityDoubleDefinition $definition): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $resolvers = $builder->getResolvers();
    $context = $definition->context;

    // Helper to wire a method, checking for overrides first.
    $wireMethod = function (string $method, callable $defaultResolver) use ($mock, $builder, $definition, $context) {
      if ($definition->hasMethod($method)) {
        $resolver = $builder->getMethodResolver($method);
        $mock->method($method)->willReturnCallback(fn(mixed ...$args) => $resolver($context, ...$args));
      }
      else {
        $mock->method($method)->willReturnCallback($defaultResolver);
      }
    };

    // Wire core entity methods (checking for overrides).
    $wireMethod('id', fn() => $resolvers['id']($context));
    $wireMethod('uuid', fn() => $resolvers['uuid']($context));
    $wireMethod('label', fn() => $resolvers['label']($context));
    $wireMethod('bundle', fn() => $resolvers['bundle']($context));
    $wireMethod('getEntityTypeId', fn() => $resolvers['getEntityTypeId']($context));

    // Wire fieldable entity methods if applicable.
    if ($definition->hasInterface(FieldableEntityInterface::class)) {
      $wireMethod('hasField', fn(string $fieldName) => $resolvers['hasField']($context, $fieldName));
      $wireMethod('get', fn(string $fieldName) => $resolvers['get']($context, $fieldName));

      if (!$definition->hasMethod('set')) {
        if ($definition->mutable) {
          $self = $mock;
          $mock->method('set')->willReturnCallback(
            function (string $fieldName, mixed $value, bool $notify = TRUE) use ($resolvers, $context, $self) {
              $resolvers['set']($context, $fieldName, $value, $notify);
              return $self;
            }
          );
        }
        else {
          $mock->method('set')->willReturnCallback(
            function (string $fieldName) {
              throw new \LogicException(
                "Cannot modify field '$fieldName' on immutable entity double. "
                . "Use createMutable() if you need to test mutations."
              );
            }
          );
        }
      }
      else {
        $resolver = $builder->getMethodResolver('set');
        $mock->method('set')->willReturnCallback(fn(mixed ...$args) => $resolver($context, ...$args));
      }
    }

    // Wire magic accessors for property-style field access.
    $wireMethod('__get', fn(string $name) => $resolvers['__get']($context, $name));

    if (!$definition->hasMethod('__set')) {
      if ($definition->mutable) {
        $mock->method('__set')->willReturnCallback(
          function (string $name, mixed $value) use ($resolvers, $context) {
            $resolvers['set']($context, $name, $value, TRUE);
          }
        );
      }
      else {
        $mock->method('__set')->willReturnCallback(
          function (string $name) {
            throw new \LogicException(
              "Cannot modify field '$name' on immutable entity double. "
              . "Use createMutable() if you need to test mutations."
            );
          }
        );
      }
    }

    // Wire toUrl - either with resolver if configured, or with exception.
    if ($definition->url !== NULL) {
      $wireMethod('toUrl', fn(?string $rel = NULL, array $options = []) => $resolvers['toUrl']($context, $rel, $options));
    }
    elseif (!$definition->hasMethod('toUrl')) {
      $mock->method('toUrl')->willReturnCallback(
        fn() => throw new \LogicException(
          "Method 'toUrl' requires url() to be configured in the entity double definition. "
          . "Add ->url('/path/to/entity') to your builder."
        )
      );
    }

    // Wire remaining method overrides (those not already wired above).
    $coreMethodsWired = [
      'id', 'uuid', 'label', 'bundle', 'getEntityTypeId',
      'hasField', 'get', 'set', '__get', '__set', 'toUrl',
    ];
    foreach ($definition->methods as $method => $override) {
      if (in_array($method, $coreMethodsWired, TRUE)) {
        // Already handled above.
        continue;
      }
      $resolver = $builder->getMethodResolver($method);
      $mock->method($method)->willReturnCallback(fn(mixed ...$args) => $resolver($context, ...$args));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function wireGuardrails(object $double, EntityDoubleDefinition $definition, array $interfaces): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $unsupportedMethods = GuardrailEnforcer::getUnsupportedMethods();

    foreach ($unsupportedMethods as $method => $reason) {
      // Skip if there's an override.
      if ($definition->hasMethod($method)) {
        continue;
      }

      // Check if the method exists on any declared interface.
      $methodExists = FALSE;
      foreach ($interfaces as $interface) {
        if (method_exists($interface, $method)) {
          $methodExists = TRUE;
          break;
        }
      }

      if ($methodExists) {
        if ($definition->lenient) {
          // In lenient mode, return null instead of throwing.
          $mock->method($method)->willReturnCallback(
            fn() => GuardrailEnforcer::getLenientDefault()
          );
        }
        else {
          $mock->method($method)->willReturnCallback(
            fn() => throw GuardrailEnforcer::createUnsupportedMethodException($method)
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function createFieldListDoubleObject(): object {
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', FieldItemListInterface::class);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function createEntityReferenceFieldListDoubleObject(): object {
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', EntityReferenceFieldItemListInterface::class);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function wireFieldListResolvers(object $double, FieldItemListDoubleBuilder $builder, EntityDoubleDefinition $definition, array $context, bool $hasEntityReferences = FALSE): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $resolvers = $builder->getResolvers();
    $fieldName = $builder->getFieldName();

    $mock->method('first')->willReturnCallback(fn() => $resolvers['first']($context));
    $mock->method('isEmpty')->willReturnCallback(fn() => $resolvers['isEmpty']($context));
    $mock->method('getValue')->willReturnCallback(fn() => $resolvers['getValue']($context));
    $mock->method('get')->willReturnCallback(fn(int $delta) => $resolvers['get']($context, $delta));
    $mock->method('__get')->willReturnCallback(fn(string $property) => $resolvers['__get']($context, $property));

    // Wire getIterator if the mock implements IteratorAggregate.
    if (method_exists($mock, 'getIterator')) {
      $mock->method('getIterator')->willReturnCallback(fn() => $resolvers['getIterator']($context));
    }

    // Wire count if the mock implements Countable.
    if (method_exists($mock, 'count')) {
      $mock->method('count')->willReturnCallback(fn() => $resolvers['count']($context));
    }

    if ($definition->mutable) {
      $self = $mock;
      $mock->method('setValue')->willReturnCallback(
        function (mixed $values, bool $notify = TRUE) use ($resolvers, $context, $self) {
          $resolvers['setValue']($context, $values, $notify);
          return $self;
        }
      );
      $mock->method('__set')->willReturnCallback(
        fn(string $property, mixed $value) => $resolvers['__set']($context, $property, $value)
      );
    }
    else {
      $mock->method('setValue')->willReturnCallback(
        function () use ($fieldName) {
          throw new \LogicException(
            "Cannot modify field '$fieldName' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
      $mock->method('__set')->willReturnCallback(
        function (string $property) use ($fieldName) {
          throw new \LogicException(
            "Cannot modify field '$fieldName' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
    }

    // Wire referencedEntities if this is an entity reference field.
    if ($hasEntityReferences) {
      $mock->method('referencedEntities')->willReturnCallback(
        fn() => $resolvers['referencedEntities']($context)
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function createFieldItemDoubleObject(): object {
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', FieldItemInterface::class);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function wireFieldItemResolvers(object $double, FieldItemDoubleBuilder $builder, bool $mutable, int $delta, string $fieldName, array $context): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $resolvers = $builder->getResolvers();

    $mock->method('__get')->willReturnCallback(fn(string $property) => $resolvers['__get']($context, $property));
    $mock->method('getValue')->willReturnCallback(fn() => $resolvers['getValue']($context));
    $mock->method('isEmpty')->willReturnCallback(fn() => $resolvers['isEmpty']($context));

    if ($mutable) {
      $self = $mock;
      $mock->method('setValue')->willReturnCallback(
        function (mixed $val, bool $notify = TRUE) use ($resolvers, $context, $self) {
          $resolvers['setValue']($context, $val, $notify);
          return $self;
        }
      );
      $mock->method('__set')->willReturnCallback(
        fn(string $property, mixed $val) => $resolvers['__set']($context, $property, $val)
      );
    }
    else {
      $mock->method('setValue')->willReturnCallback(
        function () use ($delta) {
          throw new \LogicException(
            "Cannot modify field item at delta $delta on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
      $mock->method('__set')->willReturnCallback(
        function (string $property) {
          throw new \LogicException(
            "Cannot modify property '$property' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateDouble(object $double): EntityInterface {
    // PHPUnit mocks are already usable as-is.
    /** @var \Drupal\Core\Entity\EntityInterface $double */
    return $double;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>
   *   The field item list double.
   */
  protected function instantiateFieldListDouble(object $double): FieldItemListInterface {
    // PHPUnit mocks are already usable as-is.
    /** @var \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $double */
    return $double;
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateFieldItemDouble(object $double): FieldItemInterface {
    // PHPUnit mocks are already usable as-is.
    /** @var \Drupal\Core\Field\FieldItemInterface $double */
    return $double;
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateTraitStub(EntityInterface $double, string $stubClassName): EntityInterface {
    // Create stub instance without constructor.
    $stub = (new \ReflectionClass($stubClassName))->newInstanceWithoutConstructor();

    // Copy all properties from double to stub.
    $this->copyObjectProperties($double, $stub);

    /** @var \Drupal\Core\Entity\EntityInterface $stub */
    return $stub;
  }

  /**
   * Copies all properties from source object to target object.
   *
   * Used to transfer the internal state of a PHPUnit mock object to a trait
   * stub instance that extends the mock's class.
   *
   * @param object $source
   *   The source object.
   * @param object $target
   *   The target object.
   */
  private function copyObjectProperties(object $source, object $target): void {
    $reflection = new \ReflectionObject($source);

    foreach ($reflection->getProperties() as $property) {
      $value = $property->getValue($source);
      $property->setValue($target, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function createUrlDoubleObject(): object {
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', Url::class);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function createGeneratedUrlDoubleObject(): object {
    $mock = static::invokeNonPublicMethod($this->testCase, 'createMock', GeneratedUrl::class);
    assert(is_object($mock));
    return $mock;
  }

  /**
   * {@inheritdoc}
   */
  protected function wireUrlResolvers(object $double, UrlDoubleBuilder $builder, array $context): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $resolvers = $builder->getResolvers();

    $mock->method('toString')->willReturnCallback(
      fn(bool $collectBubbleableMetadata = FALSE) => $resolvers['toString']($context, $collectBubbleableMetadata)
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function wireGeneratedUrlResolvers(object $double, string $url): void {
    /** @var \PHPUnit\Framework\MockObject\MockObject $mock */
    $mock = $double;
    $mock->method('getGeneratedUrl')->willReturn($url);
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateUrlDouble(object $double): Url {
    // PHPUnit mocks are already usable as-is.
    /** @var \Drupal\Core\Url $double */
    return $double;
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateGeneratedUrlDouble(object $double): object {
    // PHPUnit mocks are already usable as-is.
    return $double;
  }

}
