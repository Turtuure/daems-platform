<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform;

use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant;
use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenantInput;
use Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant;
use Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenantInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * GSA-only HTTP wrapper for tenant-admin grant/revoke flows.
 *
 * NOTE: the `list()` method intentionally returns an empty array for now.
 * UserTenantRepositoryInterface exposes only `countAdminsForTenant()` (Wave A);
 * the full enumeration method needed for the GSA admins page (`findAdminsForTenant`
 * returning user_id + name + email) is a Wave G read-side follow-up. The route
 * is wired so the front-end can query a stable URL today and start receiving
 * real rows once that method lands.
 */
final class TenantAdminsController
{
    public function __construct(
        private readonly GrantAdminToUserForTenant $grantAdmin,
        private readonly RevokeAdminFromUserForTenant $revokeAdmin,
        private readonly UserTenantRepositoryInterface $userTenants,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        try {
            $tenantId = TenantId::fromString($id);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $count = $this->userTenants->countAdminsForTenant($tenantId);

        // Read-side enumeration deferred — see class doc-block.
        return Response::json(['data' => [
            'admins' => [],
            'total'  => $count,
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
