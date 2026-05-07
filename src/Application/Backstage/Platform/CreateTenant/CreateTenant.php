<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\CreateTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;
use DomainException;

/**
 * GSA-only use case: create a new tenant and auto-seed tenant_modules rows
 * for every default-available, non-core manifest.
 *
 * Auto-seeded rows are AVAILABLE (available_at = now, available_by = actor)
 * but not yet ENABLED — the tenant admin still has to flip the switch via
 * EnableModuleForTenant. Core modules are skipped because they are unconditionally
 * on for every tenant by definition.
 */
final class CreateTenant
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(CreateTenantInput $input): CreateTenantOutput
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        if ($this->tenants->findBySlug($input->slug) !== null) {
            throw new DomainException("Tenant with slug '{$input->slug}' already exists");
        }

        $now = $this->clock->now();
        $tenantId = TenantId::generate();

        $tenant = new Tenant(
            id: $tenantId,
            slug: TenantSlug::fromString($input->slug),
            name: $input->slug,
            createdAt: $now,
            memberNumberPrefix: $input->memberNumberPrefix,
            defaultTimeFormat: '24',
            displayNameI18n: $input->displayNamesI18n === [] ? null : $input->displayNamesI18n,
            publicDescriptionI18n: $input->publicDescriptionsI18n === [] ? null : $input->publicDescriptionsI18n,
            supportedLocales: $input->supportedLocales,
            defaultLocale: $input->defaultLocale,
        );
        $this->tenants->save($tenant);

        foreach ($this->registry->all() as $manifest) {
            if ($manifest->isCore()) {
                continue;
            }
            if (!$manifest->defaultAvailable()) {
                continue;
            }
            $tm = new TenantModule(
                id: TenantId::generate()->value(),
                tenantId: $tenantId,
                moduleSlug: $manifest->name(),
                availableAt: $now,
                availableBy: $input->actingUserId,
                enabledAt: null,
                enabledBy: null,
                disabledAt: null,
                createdAt: $now,
                updatedAt: $now,
            );
            $this->tenantModules->save($tm);
        }

        return new CreateTenantOutput($tenantId, $now);
    }
}
