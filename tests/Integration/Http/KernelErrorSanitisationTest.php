<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use LogicException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class KernelErrorSanitisationTest extends TestCase
{
    private function kernelThatThrows(Throwable $e, bool $debug = false): Kernel
    {
        $logger = new class implements LoggerInterface {
            public array $calls = [];
            public function error(string $message, array $context = []): void
            {
                $this->calls[] = [$message, $context];
            }
        };

        $container = new Container();
        $router = new Router(static fn(string $c): MiddlewareInterface => throw new LogicException('no mw'));
        $router->get('/boom', static function () use ($e): Response {
            throw $e;
        });
        $container->singleton(Router::class, static fn(): Router => $router);

        return new Kernel($container, $logger, $debug);
    }

    public function test401ForUnauthorized(): void
    {
        $k = $this->kernelThatThrows(new UnauthorizedException());
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(401, $r->status());
        $this->assertStringContainsString('Authentication required.', $r->body());
    }

    public function test403ForForbidden(): void
    {
        $k = $this->kernelThatThrows(new ForbiddenException());
        $this->assertSame(403, $k->handle(Request::forTesting('GET', '/boom'))->status());
    }

    public function test404ForNotFound(): void
    {
        $k = $this->kernelThatThrows(new NotFoundException());
        $this->assertSame(404, $k->handle(Request::forTesting('GET', '/boom'))->status());
    }

    public function test400ForValidation(): void
    {
        $k = $this->kernelThatThrows(new ValidationException('bad input'));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(400, $r->status());
        $this->assertStringContainsString('bad input', $r->body());
    }

    public function test429ForTooMany(): void
    {
        $k = $this->kernelThatThrows(new TooManyRequestsException(900, 'slow'));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(429, $r->status());
        $this->assertSame('900', $r->header('Retry-After'));
    }

    public function testSqlStateMessageNotLeaked(): void
    {
        $k = $this->kernelThatThrows(new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'attacker@evil.com' for key 'users_email_unique'",
        ));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(500, $r->status());
        $this->assertStringNotContainsString('SQLSTATE', $r->body());
        $this->assertStringNotContainsString('Duplicate entry', $r->body());
        $this->assertStringNotContainsString('attacker@evil.com', $r->body());
        $this->assertStringContainsString('Internal server error', $r->body());
    }

    public function testDebugModeLeaksExceptionBody(): void
    {
        $k = $this->kernelThatThrows(new RuntimeException('secret-detail'), debug: true);
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(500, $r->status());
        $this->assertStringContainsString('secret-detail', $r->body());
    }

    public function testUnhandledExceptionIsLogged(): void
    {
        $logger = new class implements LoggerInterface {
            public array $calls = [];
            public function error(string $message, array $context = []): void
            {
                $this->calls[] = [$message, $context];
            }
        };
        $container = new Container();
        $router = new Router(static fn(string $c): MiddlewareInterface => throw new LogicException());
        $router->get('/boom', static function (): Response {
            throw new RuntimeException('x');
        });
        $container->singleton(Router::class, static fn(): Router => $router);

        $k = new Kernel($container, $logger, false);
        $k->handle(Request::forTesting('GET', '/boom'));

        $this->assertNotEmpty($logger->calls);
    }
}
