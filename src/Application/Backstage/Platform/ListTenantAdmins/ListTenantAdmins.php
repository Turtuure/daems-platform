<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenantAdmins;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * GSA-only read model: enumerate every active admin (role='admin') for a
 * given tenant, joined with users.name + users.email so the platform
 * tenant edit "Admins" tab can render the full list with revoke buttons.
 *
 * Counterpart to UserTenantRepositoryInterface::countAdminsForTenant() —
 * the count is also exposed here so the controller can return both in a
 * single response without a second query.
 */
final class ListTenantAdmins
{
    public function __construct(
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(ListTenantAdminsInput $input): ListTenantAdminsOutput
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $rows = [];
        foreach ($this->userTenants->findAdminsForTenant($input->tenantId) as $row) {
            $rows[] = [
                'userId'    => $row['user_id'],
                'name'      => $row['name'],
                'email'     => $row['email'],
                'grantedAt' => $row['granted_at']->format(\DateTimeInterface::ATOM),
            ];
        }

        return new ListTenantAdminsOutput($rows, count($rows));
    }
}
