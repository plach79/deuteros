<?php

declare(strict_types=1);

namespace Deuteros\Tests\Integration;

use Deuteros\Double\EntityDoubleDefinitionBuilder;
use Deuteros\Double\PhpUnit\MockEntityDoubleFactory;
use Deuteros\Double\Prophecy\ProphecyEntityDoubleFactory;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests "NodeInterface" doubles with PHPUnit and Prophecy adapters.
 *
 * These tests verify adapter parity when creating "NodeInterface" doubles
 * and that lenient mode works correctly with the full interface hierarchy.
 */
#[Group('deuteros')]
class NodeInterfaceTest extends TestCase {

  use ProphecyTrait;

  /**
   * Tests creating a "NodeInterface" double with PHPUnit mocks.
   */
  public function testNodeInterfaceWithPhpUnit(): void {
    $factory = MockEntityDoubleFactory::fromTest($this);

    $node = $factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
        ->bundle('article')
        ->id(42)
        ->label('Test Article')
        ->method('getTitle', fn() => 'Test Article Title')
        ->method('isPublished', fn() => TRUE)
        ->method('getCreatedTime', fn() => 1234567890)
        ->field('body', 'Article body content')
        ->build()
    );

    // Core EntityInterface methods.
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('node', $node->getEntityTypeId());
    $this->assertSame('article', $node->bundle());
    $this->assertSame(42, $node->id());

    // NodeInterface-specific methods.
    $this->assertSame('Test Article Title', $node->getTitle());
    $this->assertTrue($node->isPublished());
    $this->assertSame(1234567890, $node->getCreatedTime());

    // Field access.
    $this->assertSame('Article body content', $node->get('body')->value);
  }

  /**
   * Tests creating a NodeInterface double with Prophecy.
   */
  public function testNodeInterfaceWithProphecy(): void {
    $factory = ProphecyEntityDoubleFactory::fromTest($this);

    $node = $factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
        ->bundle('article')
        ->id(42)
        ->label('Test Article')
        ->method('getTitle', fn() => 'Test Article Title')
        ->method('isPublished', fn() => TRUE)
        ->method('getCreatedTime', fn() => 1234567890)
        ->field('body', 'Article body content')
        ->build()
    );

    // Core EntityInterface methods.
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('node', $node->getEntityTypeId());
    $this->assertSame('article', $node->bundle());
    $this->assertSame(42, $node->id());

    // NodeInterface-specific methods.
    $this->assertSame('Test Article Title', $node->getTitle());
    $this->assertTrue($node->isPublished());
    $this->assertSame(1234567890, $node->getCreatedTime());

    // Field access.
    $this->assertSame('Article body content', $node->get('body')->value);
  }

  /**
   * Tests lenient mode with NodeInterface.
   */
  public function testNodeInterfaceLenientMode(): void {
    $factory = MockEntityDoubleFactory::fromTest($this);

    $node = $factory->create(
      EntityDoubleDefinitionBuilder::fromInterface('node', NodeInterface::class)
        ->bundle('article')
        ->lenient()
        ->build()
    );
    $this->assertInstanceOf(NodeInterface::class, $node);

    // Unconfigured NodeInterface methods should return null in lenient mode.
    $this->assertNull($node->getTitle());
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($node->isPublished());
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($node->getCreatedTime());
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($node->isPromoted());
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($node->isSticky());

    // Unsupported methods should also return null in lenient mode.
    /** @phpstan-ignore method.impossibleType */
    $this->assertNull($node->save());
    $this->assertNull($node->delete());
  }

}
