<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform;

use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant;
use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenantInput;
use Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins;
use Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdminsInput;
use Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant;
use Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenantInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * GSA-only HTTP wrapper for tenant-admin list/grant/revoke flows.
 *
 * `list()` returns the full admins enumeration (user_id, name, email,
 * granted_at) plus a total count. The list endpoint is consumed by the
 * platform tenant-edit "Admins" tab.
 */
final class TenantAdminsController
{
    public function __construct(
        private readonly GrantAdminToUserForTenant $grantAdmin,
        private readonly RevokeAdminFromUserForTenant $revokeAdmin,
        private readonly ListTenantAdmins $listAdmins,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        try {
            $tenantId = TenantId::fromString($id);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        try {
            $output = $this->listAdmins->execute(new ListTenantAdminsInput(
                actingUserId: $actor->id,
                tenantId:     $tenantId,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }

        return Response::json(['data' => [
            'admins' => $output->admins,
            'total'  => $output->total,
        ]]);
    }

    /** @param array<string, string> $params */
    public function grant(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $tenantIdStr = $params['id'] ?? '';
        if ($tenantIdStr === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        $userIdStr = $request->string('userId') ?? '';
        if ($userIdStr === '') {
            return Response::badRequest('userId is required.');
        }

        try {
            $this->grantAdmin->execute(new GrantAdminToUserForTenantInput(
                actingUserId: $actor->id,
                targetUserId: UserId::fromString($userIdStr),
                tenantId:     TenantId::fromString($tenantIdStr),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $tenantIdStr = $params['id'] ?? '';
        $userIdStr   = $params['uid'] ?? '';
        if ($tenantIdStr === '' || $userIdStr === '') {
            return Response::badRequest('Tenant ID and user ID are required.');
        }

        try {
            $this->revokeAdmin->execute(new RevokeAdminFromUserForTenantInput(
                actingUserId: $actor->id,
                targetUserId: UserId::fromString($userIdStr),
                tenantId:     TenantId::fromString($tenantIdStr),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    private function requirePlatformAdmin(Request $request): ActingUser
    {
        $actor = $request->requireActingUser();
        if (!$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }
        return $actor;
    }
}
