<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

/**
 * In-memory test fake for the append-only module_audit log.
 *
 * Mirrors the SQL semantics: list endpoints return rows in `created_at` DESC
 * order, sliced to the caller-provided limit.
 */
final class InMemoryModuleAuditRepository implements ModuleAuditRepositoryInterface
{
    /** @var list<ModuleAuditEntry> */
    public array $entries = [];

    public function append(ModuleAuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $filtered = array_values(array_filter(
            $this->entries,
            static fn (ModuleAuditEntry $e): bool => $e->tenantId()->equals($tenantId),
        ));
        usort(
            $filtered,
            static fn (ModuleAuditEntry $a, ModuleAuditEntry $b): int =>
                $b->createdAt() <=> $a->createdAt(),
        );
        return array_slice($filtered, 0, $limit);
    }

    public function listForModule(TenantId $tenantId, string $moduleSlug, int $limit = 100): array
    {
        $filtered = array_values(array_filter(
            $this->entries,
            static fn (ModuleAuditEntry $e): bool =>
                $e->tenantId()->equals($tenantId) && $e->moduleSlug() === $moduleSlug,
        ));
        usort(
            $filtered,
            static fn (ModuleAuditEntry $a, ModuleAuditEntry $b): int =>
                $b->createdAt() <=> $a->createdAt(),
        );
        return array_slice($filtered, 0, $limit);
    }
}
