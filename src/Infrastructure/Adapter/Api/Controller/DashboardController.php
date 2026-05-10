<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Application\Dashboard\ListCatalog\ListCatalog;
use Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayoutInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

/**
 * Backstage dashboard layout + widget-catalog HTTP wrapper.
 *
 * All 4 endpoints are gated by AuthMiddleware + TenantContextMiddleware. The
 * controller itself additionally enforces "admin/moderator/GSA in the active
 * tenant" — members must not reach the dashboard. Role mapping from
 * UserTenantRole → MinRole (the dashboard's own role enum) lives in
 * `roleFrom()` so the use cases stay in domain-typed inputs.
 */
final class DashboardController
{
    public function __construct(
        private readonly GetUserLayout $get,
        private readonly SaveUserLayout $save,
        private readonly ResetUserLayout $reset,
        private readonly ListCatalog $listCatalog,
        private readonly TenantModuleResolver $modules,
    ) {}

    public function getLayout(Request $req): Response
    {
        $actor    = $this->requireDashboardUser($req);
        $tenantId = $actor->activeTenant;
        $role     = $this->roleFrom($actor);
        $modules  = $this->modules->enabledSlugsFor($tenantId);

        $output = $this->get->execute($actor->id, $tenantId, $role, $modules);

        return Response::json([
            'data' => [
                'layout'     => array_map(static fn ($e) => $e->toArray(), $output->layout()),
                'is_default' => $output->isDefault(),
                'role'       => $role->value,
            ],
        ]);
    }

    public function putLayout(Request $req): Response
    {
        $actor    = $this->requireDashboardUser($req);
        $tenantId = $actor->activeTenant;
        $role     = $this->roleFrom($actor);
        $modules  = $this->modules->enabledSlugsFor($tenantId);

        $rawLayout = $req->arrayValue('layout');
        if ($rawLayout === null) {
            return Response::json(
                ['error' => ['code' => 'invalid_layout', 'message' => '`layout` must be an array']],
                400,
            );
        }

        /** @var list<array{widget_id?: string, span?: int}> $normalised */
        $normalised = array_values($rawLayout);

        try {
            $this->save->execute(new SaveUserLayoutInput(
                $actor->id,
                $tenantId,
                $role,
                $modules,
                $normalised,
            ));
        } catch (InvalidLayout $e) {
            return Response::json(
                ['error' => ['code' => 'invalid_layout', 'message' => $e->getMessage()]],
                400,
            );
        }

        return Response::json(null, 204);
    }

    public function deleteLayout(Request $req): Response
    {
        $actor = $this->requireDashboardUser($req);
        $this->reset->execute($actor->id, $actor->activeTenant);
        return Response::json(null, 204);
    }

    public function getCatalog(Request $req): Response
    {
        $actor    = $this->requireDashboardUser($req);
        $tenantId = $actor->activeTenant;
        $role     = $this->roleFrom($actor);
        $modules  = $this->modules->enabledSlugsFor($tenantId);

        $current = $this->get->execute($actor->id, $tenantId, $role, $modules)->layout();
        $items   = $this->listCatalog->execute($role, $modules, $current);

        return Response::json([
            'data' => array_map(static fn ($i) => $i->toArray(), $items),
        ]);
    }

    /**
     * Dashboard access requires admin / moderator / GSA in the active tenant.
     * Members must NOT reach the dashboard layout endpoints — this is the
     * controller's authorisation gate (AuthMiddleware only verifies token).
     */
    private function requireDashboardUser(Request $req): ActingUser
    {
        $actor = $req->requireActingUser();
        if ($actor->isPlatformAdmin()) {
            return $actor;
        }
        $role = $actor->roleInActiveTenant;
        if ($role !== UserTenantRole::Admin && $role !== UserTenantRole::Moderator) {
            throw new ForbiddenException('dashboard_access_denied');
        }
        return $actor;
    }

    private function roleFrom(ActingUser $actor): MinRole
    {
        if ($actor->isPlatformAdmin()) {
            return MinRole::Gsa;
        }
        return match ($actor->roleInActiveTenant) {
            UserTenantRole::Admin     => MinRole::Admin,
            UserTenantRole::Moderator => MinRole::Moderator,
            default                   => MinRole::Member,
        };
    }
}
