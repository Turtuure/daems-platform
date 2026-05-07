<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\SuspendTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: stamp suspended_at + suspended_reason on a tenant.
 *
 * Suspension is a billing/policy lever — login is rejected and tenant data
 * is read-only until ReactivateTenant lifts it. The reason is required and
 * surfaced in the suspension banner, so empty reasons are rejected at the
 * Input boundary, not silently coerced.
 */
final class SuspendTenant
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(SuspendTenantInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        $this->tenants->suspend($input->tenantId, $input->reason, $this->clock->now());
    }
}
