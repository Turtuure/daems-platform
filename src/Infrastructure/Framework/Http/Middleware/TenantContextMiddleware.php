<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Shared\NotFoundException;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Tenant\TenantResolverInterface;

final class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TenantResolverInterface $resolver) {}

    public function process(Request $request, callable $next): Response
    {
        $host = $request->header('Host') ?? '';
        $tenant = $this->resolver->resolve($host);

        if ($tenant === null) {
            throw new NotFoundException('unknown_tenant');
        }

        return $next($request->withAttribute('tenant', $tenant));
    }
}
