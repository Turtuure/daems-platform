<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\ModuleState;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Tenant-admin (or platform-admin) read model: groups every module-state
 * entry into three buckets — enabled, available-not-enabled, disabled —
 * for the Settings → Modules page.
 *
 * Orphan rows (DB row exists but no manifest) only surface in the disabled
 * list when the actor is a platform admin; tenant admins never see orphans
 * because they cannot act on them and showing them would leak GSA-internal
 * state.
 */
final class ListTenantModulesForCurrentTenant
{
    public function __construct(
        private readonly TenantModuleResolver $resolver,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
    ) {}

    public function execute(
        ListTenantModulesForCurrentTenantInput $input,
    ): ListTenantModulesForCurrentTenantOutput {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null) {
            throw new ForbiddenException('actor_unknown');
        }
        $isPlatformAdmin = $actor->isPlatformAdmin();
        $isTenantAdmin = $this->userTenants->findRole($input->actingUserId, $input->tenantId) === UserTenantRole::Admin;
        if (!$isPlatformAdmin && !$isTenantAdmin) {
            throw new ForbiddenException('not_tenant_admin');
        }

        // Index tenant_modules rows by slug for cheap timestamp lookup.
        $rowsBySlug = [];
        foreach ($this->tenantModules->findByTenant($input->tenantId) as $tm) {
            $rowsBySlug[$tm->moduleSlug()] = $tm;
        }

        $enabled = [];
        $availableNotEnabled = [];
        $disabled = [];

        foreach ($this->resolver->statesForTenant($input->tenantId) as $slug => $state) {
            $manifest = $this->registry->get($slug);
            $isOrphan = $manifest === null;

            // Tenant admins never see orphans — only GSA gets the cleanup affordance.
            if ($isOrphan && !$isPlatformAdmin) {
                continue;
            }

            $row = $rowsBySlug[$slug] ?? null;
            $entry = [
                'slug'           => $slug,
                'nameKey'        => $manifest?->nameKey(),
                'descriptionKey' => $manifest?->descriptionKey(),
                'sinceAt'        => null,
            ];

            switch ($state) {
                case ModuleState::CORE:
                case ModuleState::ENABLED:
                    $entry['sinceAt'] = $row?->enabledAt()?->format(\DateTimeInterface::ATOM);
                    $enabled[] = $entry;
                    break;
                case ModuleState::AVAILABLE_NOT_ENABLED:
                    $entry['sinceAt'] = $row?->availableAt()?->format(\DateTimeInterface::ATOM);
                    $availableNotEnabled[] = $entry;
                    break;
                case ModuleState::DISABLED:
                    $entry['sinceAt'] = $row?->disabledAt()?->format(\DateTimeInterface::ATOM);
                    $disabled[] = $entry;
                    break;
            }
        }

        return new ListTenantModulesForCurrentTenantOutput(
            enabled: $enabled,
            availableNotEnabled: $availableNotEnabled,
            disabled: $disabled,
        );
    }
}
