<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface ModuleAuditRepositoryInterface
{
    public function append(ModuleAuditEntry $entry): void;

    /** @return list<ModuleAuditEntry> ordered by created_at DESC */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;

    /** @return list<ModuleAuditEntry> ordered by created_at DESC */
    public function listForModule(TenantId $tenantId, string $moduleSlug, int $limit = 100): array;
}
