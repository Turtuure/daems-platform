<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\GrantModuleAvailability;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\Exception\ModuleDependencyUnmetException;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;
use DomainException;

/**
 * GSA-only use case: mark a module AVAILABLE for a specific tenant.
 *
 * Idempotent: re-granting an already-available module writes a fresh audit
 * row but does not mutate the tenant_modules row's available_at. Core
 * modules cannot be granted because they're already always-on (the audit
 * row notes the no-op for traceability).
 *
 * Dependencies must themselves be AVAILABLE for this tenant before grant —
 * otherwise the module would be in a state where the tenant admin could
 * never enable it without the GSA cleaning up dependencies first.
 */
final class GrantModuleAvailability
{
    public function __construct(
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly ModuleAuditRepositoryInterface $audits,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(GrantModuleAvailabilityInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $manifest = $this->registry->get($input->moduleSlug);
        if ($manifest === null) {
            throw new DomainException(
                "Module '{$input->moduleSlug}' is not in this deployment"
            );
        }

        $now = $this->clock->now();

        // Core modules don't have tenant_modules rows by design — log + return.
        if ($manifest->isCore()) {
            $this->audits->append($this->buildAudit($input, ModuleAuditAction::MADE_AVAILABLE, $now));
            return;
        }

        // Verify each dependency is AVAILABLE for this tenant.
        $missingDeps = [];
        foreach ($this->registry->dependencies($input->moduleSlug) as $depSlug) {
            $depManifest = $this->registry->get($depSlug);
            if ($depManifest !== null && $depManifest->isCore()) {
                continue; // core deps are always satisfied
            }
            $depRow = $this->tenantModules->find($input->tenantId, $depSlug);
            if ($depRow === null || $depRow->availableAt() === null) {
                $missingDeps[] = $depSlug;
            }
        }
        if ($missingDeps !== []) {
            throw ModuleDependencyUnmetException::for($input->moduleSlug, $missingDeps);
        }

        $existing = $this->tenantModules->find($input->tenantId, $input->moduleSlug);

        // Idempotent re-grant: existing row already has available_at — audit only.
        if ($existing !== null && $existing->availableAt() !== null) {
            $this->audits->append($this->buildAudit($input, ModuleAuditAction::MADE_AVAILABLE, $now));
            return;
        }

        $tm = new TenantModule(
            id: $existing?->id() ?? TenantId::generate()->value(),
            tenantId: $input->tenantId,
            moduleSlug: $input->moduleSlug,
            availableAt: $now,
            availableBy: $input->actingUserId,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $existing?->createdAt() ?? $now,
            updatedAt: $now,
        );
        $this->tenantModules->save($tm);

        $this->audits->append($this->buildAudit($input, ModuleAuditAction::MADE_AVAILABLE, $now));
    }

    private function buildAudit(
        GrantModuleAvailabilityInput $input,
        ModuleAuditAction $action,
        \DateTimeImmutable $now,
    ): ModuleAuditEntry {
        return new ModuleAuditEntry(
            id: TenantId::generate()->value(),
            tenantId: $input->tenantId,
            moduleSlug: $input->moduleSlug,
            action: $action,
            actorUserId: $input->actingUserId,
            actorRole: 'platform_admin',
            reason: $input->reason,
            createdAt: $now,
        );
    }
}
