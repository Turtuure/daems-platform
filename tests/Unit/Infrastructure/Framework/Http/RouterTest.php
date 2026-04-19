<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchInvokesHandlerWhenNoMiddleware(): void
    {
        $router = new Router(static fn(string $class): MiddlewareInterface => throw new LogicException('no mw'));
        $router->get('/x', static fn(): Response => Response::json(['ok' => true]));

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(200, $resp->status());
    }

    public function testMiddlewareRunsBeforeHandlerAndCanShortCircuit(): void
    {
        $blocker = new class implements MiddlewareInterface {
            public function process(Request $r, callable $next): Response
            {
                return Response::unauthorized();
            }
        };

        $router = new Router(static fn(string $class): MiddlewareInterface => $blocker);
        $router->get('/x', static fn(): Response => Response::json(['ok' => true]), [$blocker::class]);

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(401, $resp->status());
    }

    public function testMiddlewareComposesInOrderAndReachesHandler(): void
    {
        $marker = new class implements MiddlewareInterface {
            public function process(Request $r, callable $next): Response
            {
                return $next($r);
            }
        };

        $router = new Router(static fn(string $class): MiddlewareInterface => $marker);
        $router->get('/x', static fn(Request $r): Response => Response::json(['uri' => $r->uri()]), [$marker::class]);

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('/x', $resp->body());
    }

    public function test404WhenRouteMissing(): void
    {
        $router = new Router(static fn(string $class): MiddlewareInterface => throw new LogicException());
        $resp = $router->dispatch(Request::forTesting('GET', '/nope'));
        $this->assertSame(404, $resp->status());
    }

    public function testPathParamsPassedToHandler(): void
    {
        $router = new Router(static fn(string $class): MiddlewareInterface => throw new LogicException());
        $router->get('/users/{id}', static function (Request $r, array $params): Response {
            return Response::json(['id' => $params['id']]);
        });

        $resp = $router->dispatch(Request::forTesting('GET', '/users/abc'));
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('abc', $resp->body());
    }
}
