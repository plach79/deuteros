<?php

declare(strict_types=1);

namespace Deuteros\Tests\Unit\Common;

use Deuteros\Common\UrlDoubleBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the UrlDoubleBuilder class.
 */
#[CoversClass(UrlDoubleBuilder::class)]
#[Group('deuteros')]
class UrlDoubleBuilderTest extends TestCase {

  /**
   * Tests that ::toString resolver returns URL string.
   */
  public function testToStringResolverReturnsUrl(): void {
    $builder = new UrlDoubleBuilder('/node/1');
    $resolvers = $builder->getResolvers();

    $this->assertArrayHasKey('toString', $resolvers);
    $this->assertSame('/node/1', $resolvers['toString']([]));
  }

  /**
   * Tests that ::toString(FALSE) returns URL string.
   */
  public function testToStringWithFalseReturnsUrl(): void {
    $builder = new UrlDoubleBuilder('/node/42');
    $resolvers = $builder->getResolvers();

    $this->assertSame('/node/42', $resolvers['toString']([], FALSE));
  }

  /**
   * Tests that ::toString(TRUE) returns GeneratedUrl via factory.
   */
  public function testToStringWithTrueReturnsGeneratedUrl(): void {
    $generatedUrlDouble = new \stdClass();
    $factoryCalled = FALSE;
    $capturedUrl = NULL;

    $builder = new UrlDoubleBuilder('/node/1');
    $builder->setGeneratedUrlFactory(function (string $url) use ($generatedUrlDouble, &$factoryCalled, &$capturedUrl) {
      $factoryCalled = TRUE;
      $capturedUrl = $url;
      return $generatedUrlDouble;
    });

    $resolvers = $builder->getResolvers();
    $result = $resolvers['toString']([], TRUE);

    $this->assertTrue($factoryCalled);
    $this->assertSame('/node/1', $capturedUrl);
    $this->assertSame($generatedUrlDouble, $result);
  }

  /**
   * Tests that ::toString(TRUE) throws when factory not set.
   */
  public function testToStringWithTrueThrowsWithoutFactory(): void {
    $builder = new UrlDoubleBuilder('/node/1');
    $resolvers = $builder->getResolvers();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('GeneratedUrl factory not set');

    $resolvers['toString']([], TRUE);
  }

  /**
   * Tests ::getUrl returns the URL string.
   */
  public function testGetUrlReturnsUrl(): void {
    $builder = new UrlDoubleBuilder('/node/123');
    $this->assertSame('/node/123', $builder->getUrl());
  }

}
