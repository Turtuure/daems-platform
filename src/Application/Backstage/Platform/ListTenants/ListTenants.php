<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenants;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * GSA-only read model: list every tenant on the platform with summary
 * counts (domains, admins, modules) so the platform tenants page can
 * render at-a-glance cards.
 *
 * Status filter is optional — null means "all". When set, only tenants
 * matching that status (active vs suspended) are returned.
 */
final class ListTenants
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantDomainRepositoryInterface $domains,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(ListTenantsInput $input): ListTenantsOutput
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $rows = [];
        foreach ($this->tenants->findAll() as $tenant) {
            $status = $tenant->suspended() ? 'suspended' : 'active';
            if ($input->statusFilter !== null && $status !== $input->statusFilter) {
                continue;
            }
            $rows[] = $this->summarise($tenant, $status);
        }

        return new ListTenantsOutput($rows);
    }

    /**
     * @param 'active'|'suspended' $status
     * @return array{
     *   slug: string,
     *   displayNameI18n: array<string, string>|null,
     *   status: 'active'|'suspended',
     *   domainsCount: int,
     *   adminsCount: int,
     *   modulesEnabled: int,
     *   modulesAvailable: int,
     *   suspendedAt: string|null,
     * }
     */
    private function summarise(Tenant $tenant, string $status): array
    {
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

        return [
            'slug'             => $tenant->slug->value(),
            'displayNameI18n'  => $tenant->displayNameI18n(),
            'status'           => $status,
            'domainsCount'     => count($this->domains->findByTenant($tenant->id)),
            'adminsCount'      => $this->userTenants->countAdminsForTenant($tenant->id),
            'modulesEnabled'   => $modulesEnabled,
            'modulesAvailable' => $modulesAvailable,
            'suspendedAt'      => $tenant->suspendedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
