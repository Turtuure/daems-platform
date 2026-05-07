<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\GetTenantDetail;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only read model: full tenant detail for the GSA edit page —
 * basics + i18n + domains list + module + admin counts. Does not include
 * the per-module breakdown (that's in ListTenantModules) so this stays cheap.
 */
final class GetTenantDetail
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantDomainRepositoryInterface $domains,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(GetTenantDetailInput $input): GetTenantDetailOutput
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $tenant = $this->tenants->findById($input->tenantId);
        if ($tenant === null) {
            throw new DomainException('Tenant not found');
        }

        $domains = [];
        foreach ($this->domains->findByTenant($tenant->id) as $d) {
            $domains[] = [
                'hostname'  => $d->hostname(),
                'isPrimary' => $d->isPrimary(),
                'createdAt' => $d->createdAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        $modules = $this->tenantModules->findByTenant($tenant->id);
        $modulesAvailable = 0;
        $modulesEnabled = 0;
        foreach ($modules as $row) {
            if ($row->isAvailable()) {
                $modulesAvailable++;
            }
            if ($row->isEnabled()) {
                $modulesEnabled++;
            }
        }

        return new GetTenantDetailOutput([
            'slug'                  => $tenant->slug->value(),
            'name'                  => $tenant->name,
            'displayNameI18n'       => $tenant->displayNameI18n(),
            'publicDescriptionI18n' => $tenant->publicDescriptionI18n(),
            'supportedLocales'      => $tenant->supportedLocales(),
            'defaultLocale'         => $tenant->defaultLocale(),
            'memberNumberPrefix'    => $tenant->memberNumberPrefix,
            'defaultTimeFormat'     => $tenant->defaultTimeFormat,
            'status'                => $tenant->suspended() ? 'suspended' : 'active',
            'suspendedAt'           => $tenant->suspendedAt()?->format(\DateTimeInterface::ATOM),
            'suspendedReason'       => $tenant->suspendedReason(),
            'createdAt'             => $tenant->createdAt->format(\DateTimeInterface::ATOM),
            'domains'               => $domains,
            'adminsCount'           => $this->userTenants->countAdminsForTenant($tenant->id),
            'modulesEnabled'        => $modulesEnabled,
            'modulesAvailable'      => $modulesAvailable,
        ]);
    }
}
