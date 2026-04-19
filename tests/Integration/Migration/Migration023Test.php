<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration023Test extends MigrationTestCase
{
    public function testEveryExistingUserGetsDaemsPivotRow(): void
    {
        $this->runMigrationsUpTo(22);

        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES
             ('01958000-0000-7000-8000-00000000b001', 'GSA',    'gsa2@t.fi',    'x', '1990-01-01', 'global_system_administrator'),
             ('01958000-0000-7000-8000-00000000b002', 'Admin',  'admin2@t.fi',  'x', '1990-01-01', 'admin'),
             ('01958000-0000-7000-8000-00000000b003', 'Member', 'member2@t.fi', 'x', '1990-01-01', 'member'),
             ('01958000-0000-7000-8000-00000000b004', 'Mod',    'mod2@t.fi',    'x', '1990-01-01', 'moderator')"
        );

        $this->runMigration('023_backfill_user_tenants_from_users_role.sql');

        $stmt = $this->pdo()->query(
            "SELECT u.id, ut.role FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             JOIN tenants t ON t.id = ut.tenant_id AND t.slug = 'daems'
             ORDER BY u.id"
        );
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(4, $rows);
        $this->assertSame('registered', $rows[0]['role']);  // GSA → registered in tenant
        $this->assertSame('admin',      $rows[1]['role']);
        $this->assertSame('member',     $rows[2]['role']);
        $this->assertSame('moderator',  $rows[3]['role']);
    }
}
