<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\AuthenticateToken\AuthenticateTokenInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticateToken $authenticate,
        private readonly TenantRepositoryInterface $tenants,
        private readonly UserTenantRepositoryInterface $userTenants,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            throw new UnauthorizedException();
        }

        $out = $this->authenticate->execute(new AuthenticateTokenInput($raw));
        if (!$out->isSuccess() || $out->userId === null || $out->email === null) {
            throw new UnauthorizedException();
        }

        $tenantAttr = $request->attribute('tenant');
        if (!$tenantAttr instanceof Tenant) {
            throw new \RuntimeException('TenantContextMiddleware must run before AuthMiddleware');
        }
        $tenant = $tenantAttr;

        $override = $request->header('X-Daems-Tenant');
        if ($override !== null && $override !== '') {
            if (!$out->isPlatformAdmin) {
                throw new ForbiddenException('tenant_override_forbidden');
            }
            $overrideTenant = $this->tenants->findBySlug($override);
            if ($overrideTenant === null) {
                throw new NotFoundException('unknown_tenant');
            }
            $tenant = $overrideTenant;
            $request = $request->withAttribute('tenant', $tenant);
        }

        $role = $this->userTenants->findRole($out->userId, $tenant->id);

        $actingUser = new ActingUser(
            id:                 $out->userId,
            email:              $out->email,
            isPlatformAdmin:    $out->isPlatformAdmin,
            activeTenant:       $tenant->id,
            roleInActiveTenant: $role,
        );

        $result = $next($request->withActingUser($actingUser));
        if (!$result instanceof Response) {
            throw new \RuntimeException('AuthMiddleware next() did not return Response');
        }
        return $result;
    }
}
