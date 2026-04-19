<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration021Test extends MigrationTestCase
{
    public function testGsaUsersBecomePlatformAdmin(): void
    {
        $this->runMigrationsUpTo(20);

        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES
             ('01958000-0000-7000-8000-00000000a001', 'GSA',    'gsa@t.fi',    'x', '1990-01-01', 'global_system_administrator'),
             ('01958000-0000-7000-8000-00000000a002', 'Admin',  'admin@t.fi',  'x', '1990-01-01', 'admin'),
             ('01958000-0000-7000-8000-00000000a003', 'Member', 'member@t.fi', 'x', '1990-01-01', 'member')"
        );

        $this->runMigration('021_backfill_is_platform_admin_from_role.sql');

        $stmt = $this->pdo()->query('SELECT id, is_platform_admin FROM users ORDER BY id');
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $rows[0]['is_platform_admin']);
        $this->assertSame(0, (int) $rows[1]['is_platform_admin']);
        $this->assertSame(0, (int) $rows[2]['is_platform_admin']);
    }
}
