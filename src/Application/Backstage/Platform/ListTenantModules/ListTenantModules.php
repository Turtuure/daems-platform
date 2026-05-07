<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenantModules;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Module\ModuleRegistry;
use DomainException;

/**
 * GSA-only read model: per-tenant module breakdown — every manifest
 * combined with the tenant's tenant_modules row state, plus orphan rows
 * (DB rows for slugs the registry no longer knows about, surfaced for the
 * GSA cleanup affordance).
 */
final class ListTenantModules
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly TenantModuleResolver $resolver,
        private readonly ModuleRegistry $registry,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(ListTenantModulesInput $input): ListTenantModulesOutput
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        // Lookup tenant_modules rows once + index by slug for cheap timestamp lookup.
        $rowsBySlug = [];
        foreach ($this->tenantModules->findByTenant($input->tenantId) as $tm) {
            $rowsBySlug[$tm->moduleSlug()] = $tm;
        }

        $states = $this->resolver->statesForTenant($input->tenantId);
        $out = [];
        foreach ($states as $slug => $state) {
            $manifest = $this->registry->get($slug);
            $row = $rowsBySlug[$slug] ?? null;
            $out[] = [
                'slug'             => $slug,
                'nameKey'          => $manifest?->nameKey(),
                'descriptionKey'   => $manifest?->descriptionKey(),
                'category'         => $manifest?->category(),
                'isCore'           => $manifest?->isCore() ?? false,
                'defaultAvailable' => $manifest?->defaultAvailable() ?? false,
                'dependsOn'        => $manifest?->dependsOn() ?? [],
                'state'            => $state->value,
                'availableAt'      => $row?->availableAt()?->format(\DateTimeInterface::ATOM),
                'enabledAt'        => $row?->enabledAt()?->format(\DateTimeInterface::ATOM),
                'disabledAt'       => $row?->disabledAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new ListTenantModulesOutput($out);
    }
}
