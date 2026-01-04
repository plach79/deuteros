<?php

declare(strict_types=1);

namespace Deuteros\Common;

/**
 * Enforces guardrails for entity doubles.
 *
 * Provides differentiated error messages for different failure modes:
 * - Missing resolver (method from declared interface, no override provided)
 * - Explicitly unsupported (write operations, entity storage, services)
 */
final class GuardrailEnforcer {

  /**
   * Methods that are explicitly unsupported (storage/service operations).
   *
   * @var array<string, string>
   */
  private const array UNSUPPORTED_METHODS = [
    'save' => 'Saving entities',
    'delete' => 'Deleting entities',
    'access' => 'Access checking',
    'toLink' => 'Link generation',
    'getTypedData' => 'Typed data access',
    'getFieldDefinition' => 'Field definition access',
    'getFieldDefinitions' => 'Field definition access',
    'isDefaultRevision' => 'Revision handling',
    'wasDefaultRevision' => 'Revision handling',
    'isLatestRevision' => 'Revision handling',
    'isLatestTranslationAffectedRevision' => 'Revision handling',
    'preSave' => 'Entity lifecycle hooks',
    'postSave' => 'Entity lifecycle hooks',
    'preCreate' => 'Entity lifecycle hooks',
    'postCreate' => 'Entity lifecycle hooks',
    'preDelete' => 'Entity lifecycle hooks',
    'postDelete' => 'Entity lifecycle hooks',
    'postLoad' => 'Entity lifecycle hooks',
  ];

  /**
   * Gets the unsupported methods map.
   *
   * @return array<string, string>
   *   Methods keyed by name with description of why they're unsupported.
   */
  public static function getUnsupportedMethods(): array {
    return self::UNSUPPORTED_METHODS;
  }

  /**
   * Checks if a method is explicitly unsupported.
   *
   * @param string $method
   *   The method name.
   *
   * @return bool
   *   TRUE if explicitly unsupported, FALSE otherwise.
   */
  public static function isUnsupportedMethod(string $method): bool {
    return isset(self::UNSUPPORTED_METHODS[$method]);
  }

  /**
   * Creates an exception for an explicitly unsupported method.
   *
   * @param string $method
   *   The method name.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  public static function createUnsupportedMethodException(string $method): \LogicException {
    $reason = self::UNSUPPORTED_METHODS[$method] ?? 'This operation';

    return new \LogicException(sprintf(
     "Method '%s' is not supported. %s requires runtime services. "
      . "This entity double is a unit-test value object. "
      . "Use a Kernel test for this behavior.",
      $method,
      $reason
    ));
  }

  /**
   * Creates an exception for a missing resolver.
   *
   * @param string $method
   *   The method name.
   * @param string $interface
   *   The interface declaring the method.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  public static function createMissingResolverException(string $method, string $interface): \LogicException {
    return new \LogicException(sprintf(
      "Method '%s' on interface '%s' requires a resolver in method overrides. "
      . "Add '%s' => callable to your entity double definition.",
      $method,
      $interface,
      $method
    ));
  }

  /**
   * Creates an exception for a missing resolver without known interface.
   *
   * @param string $method
   *   The method name.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  public static function createMissingResolverExceptionGeneric(string $method): \LogicException {
    return new \LogicException(sprintf(
      "Method '%s' requires a resolver in method overrides. "
      . "Add '%s' => callable to your entity double definition.",
      $method,
      $method
    ));
  }

  /**
   * Gets the default return value for lenient mode.
   *
   * In lenient mode, unconfigured methods return null instead of throwing.
   * This applies to all methods, including explicitly unsupported ones.
   *
   * @return null
   *   Always returns null.
   */
  public static function getLenientDefault(): mixed {
    return NULL;
  }

}
