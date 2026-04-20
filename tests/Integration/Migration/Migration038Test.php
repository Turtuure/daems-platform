<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration038Test extends MigrationTestCase
{
    public function test_tenant_member_counters_table_created(): void
    {
        $this->runMigrationsUpTo(37);
        $this->runMigration('038_create_tenant_member_counters.sql');

        $cols = $this->columnsOf('tenant_member_counters');
        self::assertContains('tenant_id', $cols);
        self::assertContains('next_value', $cols);
        self::assertContains('updated_at', $cols);

        $fks = $this->foreignKeysOf('tenant_member_counters');
        self::assertContains('fk_tmc_tenant', $fks);
    }

    public function test_backfill_seeds_each_existing_tenant(): void
    {
        $this->runMigrationsUpTo(37);

        $this->pdo->exec(
            "INSERT INTO tenants (id, slug, name, created_at, updated_at)
             VALUES ('t-1','test-t1','Test Tenant 1',NOW(),NOW()),
                    ('t-2','test-t2','Test Tenant 2',NOW(),NOW())"
        );
        $this->pdo->exec(
            "INSERT INTO users (id, name, email, password_hash, country, address_street, address_zip,
                                address_city, address_country, membership_type, membership_status,
                                member_number, created_at)
             VALUES ('u-1','A','a@x','h','FI','','','','','individual','active','00007',NOW()),
                    ('u-2','B','b@x','h','FI','','','','','individual','active','00003',NOW())"
        );
        $this->pdo->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('u-1','t-1','member',NOW()),
                    ('u-2','t-2','member',NOW())"
        );

        $this->runMigration('038_create_tenant_member_counters.sql');

        $byTenant = [];
        $rows = $this->pdo->query(
            "SELECT tenant_id, next_value FROM tenant_member_counters"
        )?->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $byTenant[$r['tenant_id']] = (int) $r['next_value'];
        }

        self::assertSame(8, $byTenant['t-1']);
        self::assertSame(4, $byTenant['t-2']);
    }
}
