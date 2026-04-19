<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration020Test extends MigrationTestCase
{
    public function testIsPlatformAdminColumnAdded(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        $this->assertContains('is_platform_admin', $this->columnsOf('users'));
    }

    public function testPlatformAdminAuditTableCreated(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        $stmt = $this->pdo()->query('SHOW TABLES');
        $this->assertNotFalse($stmt);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('platform_admin_audit', $tables);
    }

    public function testTriggerFiresOnPlatformAdminChange(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        // Seed a user (role column still exists from migration 006+008)
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
             VALUES ('01958000-0000-7000-8000-000000000099', 'Sam', 'sam@test.fi', 'x', '1990-01-01', 'member')"
        );

        $this->pdo()->exec("SET @app_actor_user_id = 'actor-1'");
        $this->pdo()->exec(
            "UPDATE users SET is_platform_admin = TRUE
             WHERE id = '01958000-0000-7000-8000-000000000099'"
        );

        $stmt = $this->pdo()->query(
            "SELECT action, changed_by FROM platform_admin_audit
             WHERE user_id = '01958000-0000-7000-8000-000000000099'"
        );
        $this->assertNotFalse($stmt);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($audit);
        $this->assertSame('granted', $audit['action']);
        $this->assertSame('actor-1', $audit['changed_by']);
    }

    public function testTriggerFiresOnRevoke(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role, is_platform_admin)
             VALUES ('01958000-0000-7000-8000-00000000009a', 'GSA', 'gsa@test.fi', 'x', '1990-01-01', 'admin', TRUE)"
        );

        $this->pdo()->exec("SET @app_actor_user_id = 'actor-2'");
        $this->pdo()->exec(
            "UPDATE users SET is_platform_admin = FALSE
             WHERE id = '01958000-0000-7000-8000-00000000009a'"
        );

        $stmt = $this->pdo()->query(
            "SELECT action FROM platform_admin_audit
             WHERE user_id = '01958000-0000-7000-8000-00000000009a'"
        );
        $this->assertNotFalse($stmt);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($audit);
        $this->assertSame('revoked', $audit['action']);
    }

    public function testTriggerDoesNotFireOnUnrelatedUpdate(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
             VALUES ('01958000-0000-7000-8000-00000000009b', 'X', 's@test.fi', 'x', '1990-01-01', 'member')"
        );
        $this->pdo()->exec(
            "UPDATE users SET name = 'Sammy'
             WHERE id = '01958000-0000-7000-8000-00000000009b'"
        );

        $stmt = $this->pdo()->query(
            "SELECT COUNT(*) FROM platform_admin_audit
             WHERE user_id = '01958000-0000-7000-8000-00000000009b'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
