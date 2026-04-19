<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Tenant\TenantResolverInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantContextMiddlewareTest extends TestCase
{
    public function testAttachesTenantToRequest(): void
    {
        $tenant = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems',
            new DateTimeImmutable('2026-01-01'),
        );
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->with('daems.fi')->willReturn($tenant);

        $mw = new TenantContextMiddleware($resolver);
        $req = Request::forTesting('GET', '/', headers: ['Host' => 'daems.fi']);

        $passed = null;
        $response = $mw->process($req, function (Request $r) use (&$passed): Response {
            $passed = $r;
            return Response::json([]);
        });

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(Request::class, $passed);
        $this->assertSame($tenant, $passed->attribute('tenant'));
    }

    public function testUnknownHostThrows(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $mw = new TenantContextMiddleware($resolver);
        $req = Request::forTesting('GET', '/', headers: ['Host' => 'unknown.example']);

        $this->expectExceptionMessage('unknown_tenant');
        $mw->process($req, fn (Request $r): Response => Response::json([]));
    }

    public function testMissingHostHeaderThrows(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->with('')->willReturn(null);

        $mw = new TenantContextMiddleware($resolver);
        $req = Request::forTesting('GET', '/', headers: []);

        $this->expectExceptionMessage('unknown_tenant');
        $mw->process($req, fn (Request $r): Response => Response::json([]));
    }
}
