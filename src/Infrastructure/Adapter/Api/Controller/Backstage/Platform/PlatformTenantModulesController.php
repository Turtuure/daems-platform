<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform;

use Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability;
use Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailabilityInput;
use Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules;
use Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModulesInput;
use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability;
use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailabilityInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * GSA-only HTTP wrapper for tenant_modules availability — the platform-side
 * "make this module available to tenant X" / "revoke availability" flows.
 *
 * Tenant admins use a different controller (TenantSelfModulesController) for
 * the enable/disable lever; this one is strictly platform_admin scope.
 */
final class PlatformTenantModulesController
{
    public function __construct(
        private readonly ListTenantModules $listTenantModules,
        private readonly GrantModuleAvailability $grantModuleAvailability,
        private readonly RevokeModuleAvailability $revokeModuleAvailability,
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
            $out = $this->listTenantModules->execute(new ListTenantModulesInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($id),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(['data' => ['modules' => $out->modules]]);
    }

    /** @param array<string, string> $params */
    public function availability(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $tenantIdStr = $params['id'] ?? '';
        $slug        = $params['slug'] ?? '';
        if ($tenantIdStr === '' || $slug === '') {
            return Response::badRequest('Tenant ID and module slug are required.');
        }

        $action = $request->string('action') ?? '';
        $reason = $request->string('reason');

        try {
            $tenantId = TenantId::fromString($tenantIdStr);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        try {
            switch ($action) {
                case 'grant':
                    $this->grantModuleAvailability->execute(new GrantModuleAvailabilityInput(
                        actingUserId: $actor->id,
                        tenantId:     $tenantId,
                        moduleSlug:   $slug,
                        reason:       $reason,
                    ));
                    break;
                case 'revoke':
                    $this->revokeModuleAvailability->execute(new RevokeModuleAvailabilityInput(
                        actingUserId: $actor->id,
                        tenantId:     $tenantId,
                        moduleSlug:   $slug,
                        reason:       $reason ?? '',
                    ));
                    break;
                default:
                    return Response::json([
                        'error' => "action must be 'grant' or 'revoke', got '{$action}'",
                    ], 422);
            }
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
