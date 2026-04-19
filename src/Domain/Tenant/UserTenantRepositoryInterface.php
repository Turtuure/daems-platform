<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;

interface UserTenantRepositoryInterface
{
    /** Returns null if user has no active membership in the given tenant. */
    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole;

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void;

    /** Soft-departure: sets left_at. */
    public function detach(UserId $userId, TenantId $tenantId): void;

    /** @return list<UserTenantRole> active memberships for this user */
    public function rolesForUser(UserId $userId): array;
}
