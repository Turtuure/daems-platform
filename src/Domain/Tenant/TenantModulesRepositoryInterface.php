<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantModulesRepositoryInterface
{
    /** @return list<TenantModule> ordered by module_slug ASC */
    public function findByTenant(TenantId $tenantId): array;

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule;

    public function save(TenantModule $tm): void;

    /**
     * Atomically clear available_at + enabled_at + set disabled_at AND insert
     * audit rows for the cascade. Used by RevokeModuleAvailability use case.
     *
     * @param list<ModuleAuditEntry> $auditEntries
     */
    public function revokeAvailability(
        TenantModule $tm,
        \DateTimeImmutable $now,
        array $auditEntries,
    ): void;

    /** @return list<TenantModule> rows where enabled_at IS NOT NULL, ordered by module_slug ASC */
    public function findEnabledByTenant(TenantId $tenantId): array;
}
