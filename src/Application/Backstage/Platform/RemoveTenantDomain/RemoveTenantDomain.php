<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\RemoveTenantDomain;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\TenantPrimaryDomainRequiredException;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: remove a tenant_domains row.
 *
 * Enforces the "every tenant has at least one primary domain" invariant —
 * removing the only primary throws TenantPrimaryDomainRequiredException so
 * the caller has to first add a replacement primary OR promote another row.
 */
final class RemoveTenantDomain
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantDomainRepositoryInterface $domains,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(RemoveTenantDomainInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        $existing = $this->domains->find($input->domainId);
        if ($existing === null) {
            // Idempotent: removing a non-existent row is a no-op.
            return;
        }
        $owner = $existing->tenantId();
        if ($owner === null || !$owner->equals($input->tenantId)) {
            throw new DomainException(
                "TenantDomain '{$input->domainId}' does not belong to tenant '{$input->tenantId->value()}'"
            );
        }

        if ($existing->isPrimary()) {
            $primaries = 0;
            foreach ($this->domains->findByTenant($input->tenantId) as $row) {
                if ($row->isPrimary()) {
                    $primaries++;
                }
            }
            if ($primaries <= 1) {
                throw TenantPrimaryDomainRequiredException::for($tenant->slug->value());
            }
        }

        $this->domains->remove($input->domainId);
    }
}
