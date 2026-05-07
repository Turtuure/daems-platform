<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use DateTimeImmutable;

/**
 * In-memory test fake for TenantModulesRepositoryInterface.
 *
 * Storage: a flat associative array keyed by `"<tenantId>::<moduleSlug>"`.
 * `revokeAvailability()` mirrors the SQL implementation by clearing the
 * available/enabled fields and stamping `disabled_at`/`updated_at`. Audit
 * entries passed alongside are appended to the public `$audits` list so
 * tests can inspect them without poking at private state.
 */
final class InMemoryTenantModulesRepository implements TenantModulesRepositoryInterface
{
    /** @var array<string, TenantModule> keyed by composite "<tenantId>::<slug>" */
    private array $byKey = [];

    /** @var list<ModuleAuditEntry> audit rows captured by revokeAvailability() */
    public array $audits = [];

    public function findByTenant(TenantId $tenantId): array
    {
        $matches = [];
        foreach ($this->byKey as $tm) {
            if ($tm->tenantId()->equals($tenantId)) {
                $matches[] = $tm;
            }
        }
        usort(
            $matches,
            static fn (TenantModule $a, TenantModule $b): int => strcmp($a->moduleSlug(), $b->moduleSlug()),
        );
        return $matches;
    }

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
    {
        return $this->byKey[$this->key($tenantId, $moduleSlug)] ?? null;
    }

    public function save(TenantModule $tm): void
    {
        $this->byKey[$this->key($tm->tenantId(), $tm->moduleSlug())] = $tm;
    }

    public function revokeAvailability(
        TenantModule $tm,
        DateTimeImmutable $now,
        array $auditEntries,
    ): void {
        $revoked = new TenantModule(
            id: $tm->id(),
            tenantId: $tm->tenantId(),
            moduleSlug: $tm->moduleSlug(),
            availableAt: null,
            availableBy: null,
            enabledAt: null,
            enabledBy: null,
            disabledAt: $now,
            createdAt: $tm->createdAt(),
            updatedAt: $now,
        );
        $this->byKey[$this->key($tm->tenantId(), $tm->moduleSlug())] = $revoked;

        foreach ($auditEntries as $entry) {
            $this->audits[] = $entry;
        }
    }

    public function findEnabledByTenant(TenantId $tenantId): array
    {
        $matches = [];
        foreach ($this->byKey as $tm) {
            if ($tm->tenantId()->equals($tenantId) && $tm->isEnabled()) {
                $matches[] = $tm;
            }
        }
        usort(
            $matches,
            static fn (TenantModule $a, TenantModule $b): int => strcmp($a->moduleSlug(), $b->moduleSlug()),
        );
        return $matches;
    }

    private function key(TenantId $tenantId, string $moduleSlug): string
    {
        return $tenantId->value() . '::' . $moduleSlug;
    }
}
