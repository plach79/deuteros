<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Double;

use Deuteros\Double\GuardrailEnforcer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the GuardrailEnforcer class.
 */
#[CoversClass(GuardrailEnforcer::class)]
#[Group('deuteros')]
class GuardrailEnforcerTest extends TestCase {

  /**
   * Tests that ::getUnsupportedMethods() returns expected blocked methods.
   */
  public function testGetUnsupportedMethods(): void {
    $methods = GuardrailEnforcer::getUnsupportedMethods();

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertIsArray($methods);
    $this->assertArrayHasKey('save', $methods);
    $this->assertArrayHasKey('delete', $methods);
    $this->assertArrayHasKey('access', $methods);
    // toUrl is now supported via the url() builder method.
    $this->assertArrayNotHasKey('toUrl', $methods);
  }

  /**
   * Tests ::isUnsupportedMethod() identifies blocked vs allowed methods.
   */
  public function testIsUnsupportedMethod(): void {
    $this->assertTrue(GuardrailEnforcer::isUnsupportedMethod('save'));
    $this->assertTrue(GuardrailEnforcer::isUnsupportedMethod('delete'));
    $this->assertTrue(GuardrailEnforcer::isUnsupportedMethod('access'));

    $this->assertFalse(GuardrailEnforcer::isUnsupportedMethod('id'));
    $this->assertFalse(GuardrailEnforcer::isUnsupportedMethod('bundle'));
    $this->assertFalse(GuardrailEnforcer::isUnsupportedMethod('nonexistent'));
  }

  /**
   * Tests exception message format for unsupported methods.
   */
  public function testCreateUnsupportedMethodException(): void {
    $exception = GuardrailEnforcer::createUnsupportedMethodException('save');

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertInstanceOf(\LogicException::class, $exception);
    $this->assertStringContainsString("Method 'save' is not supported", $exception->getMessage());
    $this->assertStringContainsString('unit-test value object', $exception->getMessage());
    $this->assertStringContainsString('Kernel test', $exception->getMessage());
  }

}
