<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class InMemoryUserTenantRepository implements UserTenantRepositoryInterface
{
    /** @var array<string, UserTenantRole> keyed by "{userId}:{tenantId}" */
    private array $roles = [];

    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole
    {
        return $this->roles[$this->key($userId, $tenantId)] ?? null;
    }

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void
    {
        $this->roles[$this->key($userId, $tenantId)] = $role;
    }

    public function detach(UserId $userId, TenantId $tenantId): void
    {
        unset($this->roles[$this->key($userId, $tenantId)]);
    }

    /** @return list<UserTenantRole> */
    public function rolesForUser(UserId $userId): array
    {
        $out = [];
        foreach ($this->roles as $key => $role) {
            if (str_starts_with($key, $userId->value() . ':')) {
                $out[] = $role;
            }
        }
        return $out;
    }

    private function key(UserId $userId, TenantId $tenantId): string
    {
        return $userId->value() . ':' . $tenantId->value();
    }
}
