<?php

declare(strict_types=1);

namespace Deuteros\Double\Prophecy;

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
use Prophecy\Argument;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophet;

/**
 * Factory for creating entity doubles using Prophecy.
 */
final class ProphecyEntityDoubleFactory extends EntityDoubleFactory {

  /**
   * Constructs a ProphecyEntityDoubleFactory.
   *
   * @param \Prophecy\Prophet $prophet
   *   The Prophecy prophet instance.
   */
  public function __construct(
    private readonly Prophet $prophet,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return static
   */
  public static function fromTest(TestCase $test): EntityDoubleFactoryInterface {
    $prophet = static::invokeNonPublicMethod($test, 'getProphet');
    assert($prophet instanceof Prophet);
    return new ProphecyEntityDoubleFactory($prophet);
  }

  /**
   * {@inheritdoc}
   *
   * Prophecy can handle multiple interfaces that share a common parent via
   * ::willImplement, so we override the base implementation to keep all
   * declared interfaces.
   */
  protected function resolveInterfaces(EntityDoubleDefinition $definition): array {
    // Always include "EntityInterface".
    $interfaces = [EntityInterface::class];

    // Add declared interfaces.
    foreach ($definition->interfaces as $interface) {
      if (!in_array($interface, $interfaces, TRUE)) {
        $interfaces[] = $interface;
      }
    }

    return $interfaces;
  }

  /**
   * {@inheritdoc}
   */
  protected function createDoubleForInterfaces(array $interfaces): object {
    // Use runtime interface for ::__get/::__set support.
    $runtimeInterface = $this->getOrCreateRuntimeInterface($interfaces);
    return $this->prophet->prophesize($runtimeInterface);
  }

  /**
   * {@inheritdoc}
   */
  protected function wireEntityResolvers(object $double, EntityDoubleBuilder $builder, EntityDoubleDefinition $definition): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Entity\EntityInterface> $prophecy */
    $prophecy = $double;
    $resolvers = $builder->getResolvers();
    $context = $definition->context;

    // Wire core entity methods.
    $prophecy->id()->will(fn() => $resolvers['id']($context));
    $prophecy->uuid()->will(fn() => $resolvers['uuid']($context));
    $prophecy->label()->will(fn() => $resolvers['label']($context));
    $prophecy->bundle()->will(fn() => $resolvers['bundle']($context));
    $prophecy->getEntityTypeId()->will(fn() => $resolvers['getEntityTypeId']($context));

    // Wire fieldable entity methods if applicable.
    if ($definition->hasInterface(FieldableEntityInterface::class)) {
      $prophecy->hasField(Argument::type('string'))->will(
        function (array $args) use ($resolvers, $context) {
                return $resolvers['hasField']($context, (string) $args[0]);
        }
      );
      $prophecy->get(Argument::type('string'))->will(
        function (array $args) use ($resolvers, $context) {
                return $resolvers['get']($context, (string) $args[0]);
        }
      );

      if ($definition->mutable) {
        $revealed = NULL;
        $prophecy->set(Argument::type('string'), Argument::any(), Argument::any())->will(
          function (array $args) use ($resolvers, $context, &$revealed, $prophecy): mixed {
                    $resolvers['set']($context, (string) $args[0], $args[1], $args[2] ?? TRUE);
            if ($revealed === NULL) {
              $revealed = $prophecy->reveal();
            }
            return $revealed;
          }
        );
      }
      else {
        $prophecy->set(Argument::type('string'), Argument::any(), Argument::any())->will(
          function (array $args): never {
            throw new \LogicException(
                        "Cannot modify field '" . (string) $args[0] . "' on immutable entity double. "
              . "Use createMutable() if you need to test mutations."
            );
          }
        );
      }
    }

    // Wire magic accessors using MethodProphecy.
    $getMethodProphecy = new MethodProphecy($prophecy, '__get', [Argument::type('string')]);
    $getMethodProphecy->will(fn(array $args) => $resolvers['__get']($context, (string) $args[0]));
    $prophecy->addMethodProphecy($getMethodProphecy);

    if ($definition->mutable) {
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(
        function (array $args) use ($resolvers, $context): void {
                $resolvers['set']($context, (string) $args[0], $args[1], TRUE);
        }
      );
      $prophecy->addMethodProphecy($setMethodProphecy);
    }
    else {
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(
        function (array $args): never {
          throw new \LogicException(
                    "Cannot modify field '" . (string) $args[0] . "' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
      $prophecy->addMethodProphecy($setMethodProphecy);
    }

    // Wire toUrl - either with resolver if configured, or with exception.
    if ($definition->url !== NULL) {
      $prophecy->toUrl(Argument::cetera())->will(
        fn(array $args) => $resolvers['toUrl']($context, $args[0] ?? NULL, $args[1] ?? [])
      );
    }
    elseif (!$definition->hasMethod('toUrl')) {
      $prophecy->toUrl(Argument::cetera())->will(
        fn() => throw new \LogicException(
          "Method 'toUrl' requires url() to be configured in the entity double definition. "
          . "Add ->url('/path/to/entity') to your builder."
        )
      );
    }

    // Wire method overrides.
    foreach ($definition->methods as $method => $override) {
      $resolver = $builder->getMethodResolver($method);
      $prophecy->$method(Argument::cetera())->will(fn(array $args) => $resolver($context, ...$args));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function wireGuardrails(object $double, EntityDoubleDefinition $definition, array $interfaces): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Entity\EntityInterface> $prophecy */
    $prophecy = $double;
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
          $prophecy->$method(Argument::cetera())->will(
            fn() => GuardrailEnforcer::getLenientDefault()
          );
        }
        else {
          $prophecy->$method(Argument::cetera())->will(
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
    return $this->prophet->prophesize(FieldItemListInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function createEntityReferenceFieldListDoubleObject(): object {
    return $this->prophet->prophesize(EntityReferenceFieldItemListInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function wireFieldListResolvers(object $double, FieldItemListDoubleBuilder $builder, EntityDoubleDefinition $definition, array $context, bool $hasEntityReferences = FALSE): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>> $prophecy */
    $prophecy = $double;
    $resolvers = $builder->getResolvers();
    $fieldName = $builder->getFieldName();

    $prophecy->first()->will(fn() => $resolvers['first']($context));
    $prophecy->isEmpty()->will(fn() => $resolvers['isEmpty']($context));
    $prophecy->getValue()->will(fn() => $resolvers['getValue']($context));
    $prophecy->get(Argument::type('int'))->will(fn(array $args) => $resolvers['get']($context, (int) $args[0]));

    // Manually add MethodProphecy for ::__get since Prophecy's "ObjectProphecy"
    // intercepts ::__get calls instead of treating them as method stubs.
    $getMethodProphecy = new MethodProphecy($prophecy, '__get', [Argument::type('string')]);
    $getMethodProphecy->will(fn(array $args) => $resolvers['__get']($context, (string) $args[0]));
    $prophecy->addMethodProphecy($getMethodProphecy);

    if ($definition->mutable) {
      $revealed = NULL;
      $prophecy->setValue(Argument::any(), Argument::any())->will(
        function (array $args) use ($resolvers, $context, &$revealed, $prophecy): mixed {
          $resolvers['setValue']($context, $args[0], $args[1] ?? TRUE);
          if ($revealed === NULL) {
            $revealed = $prophecy->reveal();
          }
          return $revealed;
        }
      );

      // Manually add "MethodProphecy" for ::__set.
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(fn(array $args) => $resolvers['__set']($context, (string) $args[0], $args[1]));
      $prophecy->addMethodProphecy($setMethodProphecy);
    }
    else {
      $prophecy->setValue(Argument::any(), Argument::any())->will(
        function () use ($fieldName): never {
          throw new \LogicException(
            "Cannot modify field '$fieldName' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );

      // Manually add MethodProphecy for ::__set.
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(
        function () use ($fieldName): never {
          throw new \LogicException(
            "Cannot modify field '$fieldName' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
      $prophecy->addMethodProphecy($setMethodProphecy);
    }

    // Wire referencedEntities if this is an entity reference field.
    if ($hasEntityReferences) {
      $prophecy->referencedEntities()->will(fn() => $resolvers['referencedEntities']($context));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function createFieldItemDoubleObject(): object {
    return $this->prophet->prophesize(FieldItemInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function wireFieldItemResolvers(object $double, FieldItemDoubleBuilder $builder, bool $mutable, int $delta, string $fieldName, array $context): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Field\FieldItemInterface> $prophecy */
    $prophecy = $double;
    $resolvers = $builder->getResolvers();

    // Manually add "MethodProphecy" for ::__get since Prophecy's
    // "ObjectProphecy" intercepts ::__get calls instead of treating them as
    // method stubs.
    $getMethodProphecy = new MethodProphecy($prophecy, '__get', [Argument::type('string')]);
    $getMethodProphecy->will(fn(array $args) => $resolvers['__get']($context, (string) $args[0]));
    $prophecy->addMethodProphecy($getMethodProphecy);

    $prophecy->getValue()->will(fn() => $resolvers['getValue']($context));
    $prophecy->isEmpty()->will(fn() => $resolvers['isEmpty']($context));

    if ($mutable) {
      $revealed = NULL;
      $prophecy->setValue(Argument::any(), Argument::any())->will(
        function (array $args) use ($resolvers, $context, &$revealed, $prophecy): mixed {
          $resolvers['setValue']($context, $args[0], $args[1] ?? TRUE);
          if ($revealed === NULL) {
            $revealed = $prophecy->reveal();
          }
          return $revealed;
        }
      );

      // Manually add "MethodProphecy" for ::__set.
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(fn(array $args) => $resolvers['__set']($context, (string) $args[0], $args[1]));
      $prophecy->addMethodProphecy($setMethodProphecy);
    }
    else {
      $prophecy->setValue(Argument::any(), Argument::any())->will(
        function () use ($delta): never {
          throw new \LogicException(
            "Cannot modify field item at delta $delta on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );

      // Manually add "MethodProphecy" for ::__set.
      $setMethodProphecy = new MethodProphecy($prophecy, '__set', [Argument::type('string'), Argument::any()]);
      $setMethodProphecy->will(
        function (array $args): never {
          $name = $args[0];
          assert(is_string($name));
          throw new \LogicException(
            "Cannot modify property '" . $name . "' on immutable entity double. "
            . "Use createMutable() if you need to test mutations."
          );
        }
      );
      $prophecy->addMethodProphecy($setMethodProphecy);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateDouble(object $double): EntityInterface {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Entity\EntityInterface> $prophecy */
    $prophecy = $double;
    /** @var \Drupal\Core\Entity\EntityInterface */
    return $prophecy->reveal();
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>
   *   The field item list double.
   */
  protected function instantiateFieldListDouble(object $double): FieldItemListInterface {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>> $prophecy */
    $prophecy = $double;
    /** @var \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> */
    return $prophecy->reveal();
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateFieldItemDouble(object $double): FieldItemInterface {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Field\FieldItemInterface> $prophecy */
    $prophecy = $double;
    /** @var \Drupal\Core\Field\FieldItemInterface */
    return $prophecy->reveal();
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateTraitStub(EntityInterface $double, string $stubClassName): EntityInterface {
    // Create stub instance without constructor.
    $stub = (new \ReflectionClass($stubClassName))->newInstanceWithoutConstructor();

    // Copy the prophecy closure (critical for Prophecy method delegation).
    // Prophecy revealed objects store their connection to the ObjectProphecy
    // in the objectProphecyClosure property.
    $property = new \ReflectionProperty($double, 'objectProphecyClosure');
    $objectProphecyClosure = $property->getValue($double);
    $property->setValue($stub, $objectProphecyClosure);

    /** @var \Drupal\Core\Entity\EntityInterface $stub */
    return $stub;
  }

  /**
   * {@inheritdoc}
   */
  protected function createUrlDoubleObject(): object {
    return $this->prophet->prophesize(Url::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function createGeneratedUrlDoubleObject(): object {
    return $this->prophet->prophesize(GeneratedUrl::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function wireUrlResolvers(object $double, UrlDoubleBuilder $builder, array $context): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Url> $prophecy */
    $prophecy = $double;
    $resolvers = $builder->getResolvers();

    $prophecy->toString(Argument::any())->will(
      fn(array $args) => $resolvers['toString']($context, $args[0] ?? FALSE)
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function wireGeneratedUrlResolvers(object $double, string $url): void {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\GeneratedUrl> $prophecy */
    $prophecy = $double;
    $prophecy->getGeneratedUrl()->willReturn($url);
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateUrlDouble(object $double): Url {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Url> $prophecy */
    $prophecy = $double;
    /** @var \Drupal\Core\Url */
    return $prophecy->reveal();
  }

  /**
   * {@inheritdoc}
   */
  protected function instantiateGeneratedUrlDouble(object $double): object {
    /** @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\GeneratedUrl> $prophecy */
    $prophecy = $double;
    return $prophecy->reveal();
  }

}
