<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ReactivateTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: clear suspended_at + suspended_reason on a tenant.
 * Idempotent — calling reactivate on a non-suspended tenant is a no-op
 * (the underlying repository writes NULL where NULL is already stored).
 */
final class ReactivateTenant
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(ReactivateTenantInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        $this->tenants->reactivate($input->tenantId);
    }
}
