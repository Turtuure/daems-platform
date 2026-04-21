<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Integration\MigrationTestCase;

abstract class IsolationTestCase extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(56);
        $this->seedTenants();
    }

    protected function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException("Tenant not found for slug: {$slug}");
        }
        return TenantId::fromString($id);
    }

    protected function seedTenants(): void
    {
        // daems + sahegroup are already seeded by migration 019.
    }

    protected function seedUser(string $id, string $email, bool $isPlatformAdmin = false): UserId
    {
        $flag = $isPlatformAdmin ? 1 : 0;
        $stmt = $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, 'User', $email, 'x', '1990-01-01', $flag]);
        return UserId::fromString($id);
    }

    protected function attachUserToTenant(UserId $userId, string $tenantSlug, UserTenantRole $role): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?)'
        );
        $stmt->execute([$userId->value(), $tenantSlug, $role->value]);
    }

    protected function makeActingUser(
        string $tenantSlug,
        ?UserTenantRole $role,
        bool $isPlatformAdmin = false,
        string $userId = '01958000-0000-7000-8000-00000000aaaa',
        string $email = 'actor@test',
    ): ActingUser {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->seedUser($userId, $email, $isPlatformAdmin);
        }
        if ($role !== null) {
            $this->attachUserToTenant(UserId::fromString($userId), $tenantSlug, $role);
        }

        return new ActingUser(
            id:                 UserId::fromString($userId),
            email:              $email,
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId($tenantSlug),
            roleInActiveTenant: $role,
        );
    }
}
