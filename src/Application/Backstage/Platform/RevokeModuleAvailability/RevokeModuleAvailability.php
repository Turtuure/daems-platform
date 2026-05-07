<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\RevokeModuleAvailability;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;
use DateTimeImmutable;

/**
 * GSA-only use case: revoke availability of a module for a tenant, cascading
 * the disable to every dependent module that is currently enabled.
 *
 * Cascade order matters — dependents are force-disabled BEFORE the root's
 * availability is cleared, so the system never observes a state where a
 * dependent is "available" but its dependency is gone.
 *
 * Each cascade step writes a `DISABLED` audit entry. The root step writes
 * `REVOKED_AVAILABILITY`. The repository's revokeAvailability() handles
 * the root atomically (clear available_at + enabled_at, set disabled_at,
 * append the root audit entry) — we pass cascaded audit entries to the
 * dependent saves manually since they aren't atomic with the root.
 */
final class RevokeModuleAvailability
{
    public function __construct(
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly ModuleAuditRepositoryInterface $audits,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(RevokeModuleAvailabilityInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $rootRow = $this->tenantModules->find($input->tenantId, $input->moduleSlug);
        if ($rootRow === null) {
            // Idempotent — nothing to revoke.
            return;
        }

        $now = $this->clock->now();

        // Walk dependents transitively, collecting only those whose row is
        // currently enabled for this tenant. Disabled / unavailable
        // dependents need no action.
        $cascadeQueue = [$input->moduleSlug];
        $cascadeSlugs = [];
        $seen = [$input->moduleSlug => true];
        while ($cascadeQueue !== []) {
            $head = array_shift($cascadeQueue);
            foreach ($this->registry->dependents($head) as $depSlug) {
                if (isset($seen[$depSlug])) {
                    continue;
                }
                $seen[$depSlug] = true;
                $depRow = $this->tenantModules->find($input->tenantId, $depSlug);
                if ($depRow !== null && $depRow->isEnabled()) {
                    $cascadeSlugs[] = $depSlug;
                }
                // Continue walking even past non-enabled dependents — their
                // own dependents could still be enabled (rare but possible
                // with weird mid-state configurations).
                $cascadeQueue[] = $depSlug;
            }
        }

        // Phase 1: cascade-disable each enabled dependent. Persist the
        // updated row + matching audit entry.
        foreach ($cascadeSlugs as $depSlug) {
            $depRow = $this->tenantModules->find($input->tenantId, $depSlug);
            if ($depRow === null) {
                continue;
            }
            $disabled = new TenantModule(
                id: $depRow->id(),
                tenantId: $depRow->tenantId(),
                moduleSlug: $depRow->moduleSlug(),
                availableAt: $depRow->availableAt(),
                availableBy: $depRow->availableBy(),
                enabledAt: null,
                enabledBy: null,
                disabledAt: $now,
                createdAt: $depRow->createdAt(),
                updatedAt: $now,
            );
            $this->tenantModules->save($disabled);
            $this->audits->append($this->makeAudit(
                tenantId: $input->tenantId,
                moduleSlug: $depSlug,
                action: ModuleAuditAction::DISABLED,
                actorUserId: $input->actingUserId,
                reason: "cascade from {$input->moduleSlug}",
                now: $now,
            ));
        }

        // Phase 2: revoke availability of the root atomically.
        $rootAudit = $this->makeAudit(
            tenantId: $input->tenantId,
            moduleSlug: $input->moduleSlug,
            action: ModuleAuditAction::REVOKED_AVAILABILITY,
            actorUserId: $input->actingUserId,
            reason: $input->reason,
            now: $now,
        );
        $this->tenantModules->revokeAvailability($rootRow, $now, [$rootAudit]);
    }

    private function makeAudit(
        TenantId $tenantId,
        string $moduleSlug,
        ModuleAuditAction $action,
        UserId $actorUserId,
        string $reason,
        DateTimeImmutable $now,
    ): ModuleAuditEntry {
        return new ModuleAuditEntry(
            id: TenantId::generate()->value(),
            tenantId: $tenantId,
            moduleSlug: $moduleSlug,
            action: $action,
            actorUserId: $actorUserId,
            actorRole: 'platform_admin',
            reason: $reason,
            createdAt: $now,
        );
    }
}
