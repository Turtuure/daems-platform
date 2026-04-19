<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PDO;

final class SqlUserTenantRepository implements UserTenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }
        $role = $row['role'] ?? null;
        return is_string($role) ? UserTenantRole::tryFrom($role) : null;
    }

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at, left_at)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value(), $role->value]);
    }

    public function detach(UserId $userId, TenantId $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_tenants SET left_at = NOW() WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
    }

    /** @return list<UserTenantRole> */
    public function rolesForUser(UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_string($value)) {
                $role = UserTenantRole::tryFrom($value);
                if ($role !== null) {
                    $out[] = $role;
                }
            }
        }
        return $out;
    }
}
