<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantDomainRepositoryInterface
{
    /** @return list<TenantDomain> */
    public function findByTenant(TenantId $tenantId): array;

    public function find(string $domainId): ?TenantDomain;

    public function add(TenantDomain $domain): void;

    /** Update hostname and/or primary flag on existing row. */
    public function update(TenantDomain $domain): void;

    public function remove(string $domainId): void;

    /** Set domainId as primary AND demote any other primary in same tenant atomically. */
    public function setPrimary(TenantId $tenantId, string $domainId): void;
}
