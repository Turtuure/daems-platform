<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class ActingUser
{
    public function __construct(
        public readonly UserId $id,
        public readonly string $email,
        public readonly bool $isPlatformAdmin,
        public readonly TenantId $activeTenant,
        public readonly ?UserTenantRole $roleInActiveTenant,
    ) {}

    public function isPlatformAdmin(): bool
    {
        return $this->isPlatformAdmin;
    }

    public function roleIn(TenantId $tenant): ?UserTenantRole
    {
        if (!$tenant->equals($this->activeTenant)) {
            return null;
        }
        return $this->roleInActiveTenant;
    }

    public function isAdminIn(TenantId $tenant): bool
    {
        if ($this->isPlatformAdmin) {
            return true;
        }
        return $this->roleIn($tenant) === UserTenantRole::Admin;
    }

    /**
     * Backward-compat alias: "is admin in the active tenant, or is platform admin".
     * Remove after Task 19 of PR 2 when all callers are updated.
     */
    public function isAdmin(): bool
    {
        return $this->isAdminIn($this->activeTenant);
    }

    /** Backward-compat alias kept TEMPORARILY. Remove after Task 19 of this PR when all callers updated. */
    public function owns(UserId $id): bool
    {
        return $this->id->equals($id);
    }
}
