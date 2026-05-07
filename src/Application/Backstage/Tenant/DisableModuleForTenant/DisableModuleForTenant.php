<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Tenant\DisableModuleForTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\Exception\ModuleDependentEnabledException;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Tenant-admin (or platform-admin) use case: turn OFF a module for this tenant.
 *
 * Refuses to disable while any dependent module is still enabled — the
 * tenant admin must disable dependents first. Idempotent: disabling an
 * already-disabled (or never-enabled) module is a no-op.
 */
final class DisableModuleForTenant
{
    public function __construct(
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly ModuleAuditRepositoryInterface $audits,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(DisableModuleForTenantInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null) {
            throw new ForbiddenException('actor_unknown');
        }
        $isPlatformAdmin = $actor->isPlatformAdmin();
        $isTenantAdmin = $this->userTenants->findRole($input->actingUserId, $input->tenantId) === UserTenantRole::Admin;
        if (!$isPlatformAdmin && !$isTenantAdmin) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $row = $this->tenantModules->find($input->tenantId, $input->moduleSlug);
        if ($row === null || !$row->isEnabled()) {
            // Idempotent — nothing enabled to turn off.
            return;
        }

        // Block disable while any dependent is enabled.
        $blockers = [];
        foreach ($this->registry->dependents($input->moduleSlug) as $depSlug) {
            $depRow = $this->tenantModules->find($input->tenantId, $depSlug);
            if ($depRow !== null && $depRow->isEnabled()) {
                $blockers[] = $depSlug;
            }
        }
        if ($blockers !== []) {
            throw ModuleDependentEnabledException::for($input->moduleSlug, $blockers);
        }

        $now = $this->clock->now();
        $disabled = new TenantModule(
            id: $row->id(),
            tenantId: $row->tenantId(),
            moduleSlug: $row->moduleSlug(),
            availableAt: $row->availableAt(),
            availableBy: $row->availableBy(),
            enabledAt: null,
            enabledBy: null,
            disabledAt: $now,
            createdAt: $row->createdAt(),
            updatedAt: $now,
        );
        $this->tenantModules->save($disabled);

        $this->audits->append(new ModuleAuditEntry(
            id: TenantId::generate()->value(),
            tenantId: $input->tenantId,
            moduleSlug: $input->moduleSlug,
            action: ModuleAuditAction::DISABLED,
            actorUserId: $input->actingUserId,
            actorRole: $isPlatformAdmin ? 'platform_admin' : 'tenant_admin',
            reason: null,
            createdAt: $now,
        ));
    }
}
