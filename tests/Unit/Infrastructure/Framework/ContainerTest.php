<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework;

use Daems\Infrastructure\Framework\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testBindAndMakeReturnsBuiltInstance(): void
    {
        $container = new Container();
        $container->bind(stdClass::class, fn() => new stdClass());

        $instance = $container->make(stdClass::class);

        $this->assertInstanceOf(stdClass::class, $instance);
    }

    public function testBindReturnsDifferentInstanceEachCall(): void
    {
        $container = new Container();
        $container->bind(stdClass::class, fn() => new stdClass());

        $a = $container->make(stdClass::class);
        $b = $container->make(stdClass::class);

        $this->assertNotSame($a, $b);
    }

    public function testSingletonReturnsSameInstanceEachCall(): void
    {
        $container = new Container();
        $container->singleton(stdClass::class, fn() => new stdClass());

        $a = $container->make(stdClass::class);
        $b = $container->make(stdClass::class);

        $this->assertSame($a, $b);
    }

    public function testMakeThrowsForUnregisteredAbstract(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->make('Unregistered\Service');
    }

    public function testFactoryReceivesContainerAsArgument(): void
    {
        $container = new Container();

        $receivedContainer = null;
        $container->bind('service', function (Container $c) use (&$receivedContainer) {
            $receivedContainer = $c;
            return new stdClass();
        });

        $container->make('service');

        $this->assertSame($container, $receivedContainer);
    }

    public function testSingletonFactoryCalledOnlyOnce(): void
    {
        $container  = new Container();
        $callCount  = 0;

        $container->singleton('counter', function () use (&$callCount) {
            $callCount++;
            return new stdClass();
        });

        $container->make('counter');
        $container->make('counter');
        $container->make('counter');

        $this->assertSame(1, $callCount);
    }
}
