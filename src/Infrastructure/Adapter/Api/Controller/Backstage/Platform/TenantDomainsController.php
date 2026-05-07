<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform;

use Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain;
use Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomainInput;
use Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain;
use Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomainInput;
use Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain;
use Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomainInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * GSA-only HTTP wrapper for tenant_domains lifecycle.
 *
 * The list endpoint reads via TenantDomainRepositoryInterface directly
 * (no use case) since it's a trivial fetch + project; the platform-admin
 * gate is still enforced at the controller boundary so non-GSA actors
 * cannot enumerate other tenants' domains.
 */
final class TenantDomainsController
{
    public function __construct(
        private readonly AddTenantDomain $addTenantDomain,
        private readonly UpdateTenantDomain $updateTenantDomain,
        private readonly RemoveTenantDomain $removeTenantDomain,
        private readonly TenantDomainRepositoryInterface $domains,
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

        $rows = [];
        foreach ($this->domains->findByTenant($tenantId) as $d) {
            $rows[] = [
                'hostname'  => $d->hostname(),
                'isPrimary' => $d->isPrimary(),
                'createdAt' => $d->createdAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return Response::json(['data' => ['domains' => $rows]]);
    }

    /** @param array<string, string> $params */
    public function add(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        $hostname  = $request->string('hostname') ?? '';
        $isPrimary = $request->bool('isPrimary', false) ?? false;

        try {
            $this->addTenantDomain->execute(new AddTenantDomainInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($id),
                hostname:     $hostname,
                isPrimary:    $isPrimary,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $tenantIdStr = $params['id'] ?? '';
        $domainId    = $params['did'] ?? '';
        if ($tenantIdStr === '' || $domainId === '') {
            return Response::badRequest('Tenant ID and domain hostname are required.');
        }

        $hostname  = $request->string('hostname');
        $isPrimary = $request->bool('isPrimary');

        try {
            $this->updateTenantDomain->execute(new UpdateTenantDomainInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($tenantIdStr),
                domainId:     $domainId,
                hostname:     $hostname,
                isPrimary:    $isPrimary,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    /** @param array<string, string> $params */
    public function remove(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $tenantIdStr = $params['id'] ?? '';
        $domainId    = $params['did'] ?? '';
        if ($tenantIdStr === '' || $domainId === '') {
            return Response::badRequest('Tenant ID and domain hostname are required.');
        }

        try {
            $this->removeTenantDomain->execute(new RemoveTenantDomainInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($tenantIdStr),
                domainId:     $domainId,
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
