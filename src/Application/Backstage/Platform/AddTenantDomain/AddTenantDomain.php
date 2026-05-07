<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\AddTenantDomain;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: register a new hostname for a tenant.
 *
 * If $isPrimary is true, demotes any existing primary atomically via
 * setPrimary() so the tenant always has exactly one primary.
 */
final class AddTenantDomain
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantDomainRepositoryInterface $domains,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(AddTenantDomainInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        $domain = TenantDomain::create(
            hostname: $input->hostname,
            tenantId: $input->tenantId,
            isPrimary: $input->isPrimary,
            createdAt: $this->clock->now(),
        );
        $this->domains->add($domain);

        if ($input->isPrimary) {
            $this->domains->setPrimary($input->tenantId, $domain->id());
        }
    }
}
