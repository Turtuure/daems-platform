<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantRepositoryInterface
{
    public function findById(TenantId $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    public function findByDomain(string $domain): ?Tenant;

    /** @return list<Tenant> */
    public function findAll(): array;

    public function updatePrefix(TenantId $tenantId, ?string $prefix): void;

    public function updateDefaultTimeFormat(TenantId $tenantId, string $format): void;
}
