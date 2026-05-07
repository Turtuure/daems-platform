<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant;

use Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant;
use Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenantInput;
use Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant;
use Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenantInput;
use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant;
use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenantInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * Tenant-scope HTTP wrapper for the Settings → Modules page. The tenant id
 * is resolved from the request's `tenant` attribute (set by
 * TenantContextMiddleware), NOT from the URL — this controller only reasons
 * about the caller's CURRENT tenant.
 *
 * Authorisation: caller must be admin in the current tenant OR a platform
 * admin. The use cases re-check this independently, but this controller
 * fails fast with 403 before the use case even starts so we don't waste
 * a DB round-trip on obvious denials.
 */
final class TenantSelfModulesController
{
    public function __construct(
        private readonly ListTenantModulesForCurrentTenant $listForCurrent,
        private readonly EnableModuleForTenant $enableModule,
        private readonly DisableModuleForTenant $disableModule,
        private readonly UserTenantRepositoryInterface $userTenants,
    ) {}

    public function list(Request $request): Response
    {
        $tenant = $this->requireTenant($request);
        $actor  = $this->requireTenantAdmin($request, $tenant->id);

        try {
            $out = $this->listForCurrent->execute(new ListTenantModulesForCurrentTenantInput(
                actingUserId: $actor->id,
                tenantId:     $tenant->id,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }

        return Response::json(['data' => [
            'enabled'             => $out->enabled,
            'availableNotEnabled' => $out->availableNotEnabled,
            'disabled'            => $out->disabled,
        ]]);
    }

    /** @param array<string, string> $params */
    public function state(Request $request, array $params): Response
    {
        $tenant = $this->requireTenant($request);
        $actor  = $this->requireTenantAdmin($request, $tenant->id);
        $slug   = $params['slug'] ?? '';
        if ($slug === '') {
            return Response::badRequest('Module slug is required.');
        }

        $action = $request->string('action') ?? '';

        try {
            switch ($action) {
                case 'enable':
                    $this->enableModule->execute(new EnableModuleForTenantInput(
                        actingUserId: $actor->id,
                        tenantId:     $tenant->id,
                        moduleSlug:   $slug,
                    ));
                    break;
                case 'disable':
                    $this->disableModule->execute(new DisableModuleForTenantInput(
                        actingUserId: $actor->id,
                        tenantId:     $tenant->id,
                        moduleSlug:   $slug,
                    ));
                    break;
                default:
                    return Response::json([
                        'error' => "action must be 'enable' or 'disable', got '{$action}'",
                    ], 422);
            }
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }

    private function requireTenantAdmin(Request $request, TenantId $tenantId): ActingUser
    {
        $actor = $request->requireActingUser();
        if ($actor->isPlatformAdmin()) {
            return $actor;
        }
        $role = $this->userTenants->findRole($actor->id, $tenantId);
        if ($role !== UserTenantRole::Admin) {
            throw new ForbiddenException('not_tenant_admin');
        }
        return $actor;
    }
}
