<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Tenant\EnableModuleForTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\Exception\ModuleDependencyUnmetException;
use Daems\Domain\Tenant\Exception\ModuleNotAvailableException;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Tenant-admin (or platform-admin) use case: turn ON a module that the GSA
 * already made available to this tenant.
 *
 * Pre-conditions:
 *   - actor is admin in this tenant (or platform admin)
 *   - tenant_modules row exists with available_at non-null
 *   - every depends_on slug is itself ENABLED for this tenant
 *
 * Idempotent: enabling an already-enabled module is a no-op (no audit row).
 */
final class EnableModuleForTenant
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly ModuleAuditRepositoryInterface $audits,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(EnableModuleForTenantInput $input): void
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

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new \DomainException('Tenant not found');
        }

        $row = $this->tenantModules->find($input->tenantId, $input->moduleSlug);
        if ($row === null || $row->availableAt() === null) {
            throw ModuleNotAvailableException::for($input->moduleSlug, $tenant->slug->value());
        }

        // Idempotent — already enabled.
        if ($row->isEnabled()) {
            return;
        }

        // Verify each dependency is ENABLED for this tenant.
        $missing = [];
        foreach ($this->registry->dependencies($input->moduleSlug) as $depSlug) {
            $depManifest = $this->registry->get($depSlug);
            if ($depManifest !== null && $depManifest->isCore()) {
                continue;
            }
            $depRow = $this->tenantModules->find($input->tenantId, $depSlug);
            if ($depRow === null || !$depRow->isEnabled()) {
                $missing[] = $depSlug;
            }
        }
        if ($missing !== []) {
            throw ModuleDependencyUnmetException::for($input->moduleSlug, $missing);
        }

        $now = $this->clock->now();
        $enabled = new TenantModule(
            id: $row->id(),
            tenantId: $row->tenantId(),
            moduleSlug: $row->moduleSlug(),
            availableAt: $row->availableAt(),
            availableBy: $row->availableBy(),
            enabledAt: $now,
            enabledBy: $input->actingUserId,
            disabledAt: null,
            createdAt: $row->createdAt(),
            updatedAt: $now,
        );
        $this->tenantModules->save($enabled);

        $this->audits->append(new ModuleAuditEntry(
            id: TenantId::generate()->value(),
            tenantId: $input->tenantId,
            moduleSlug: $input->moduleSlug,
            action: ModuleAuditAction::ENABLED,
            actorUserId: $input->actingUserId,
            actorRole: $isPlatformAdmin ? 'platform_admin' : 'tenant_admin',
            reason: null,
            createdAt: $now,
        ));
    }
}
