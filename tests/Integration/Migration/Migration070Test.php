<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration070Test extends MigrationTestCase
{
    public function testModuleAuditTableExistsWithExpectedColumns(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('070_create_module_audit_table.sql');

        $columns = $this->columnsOf('module_audit');
        $expected = [
            'id',
            'tenant_id',
            'module_slug',
            'action',
            'actor_user_id',
            'actor_role',
            'reason',
            'created_at',
        ];
        foreach ($expected as $column) {
            $this->assertContains($column, $columns, "expected column {$column}");
        }
    }

    public function testActionEnumIncludesAllFourValues(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('070_create_module_audit_table.sql');

        $row = $this->pdo()->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_audit' AND COLUMN_NAME = 'action'"
        )?->fetchColumn();

        $this->assertIsString($row);
        $this->assertStringContainsString("'made_available'", $row);
        $this->assertStringContainsString("'revoked_availability'", $row);
        $this->assertStringContainsString("'enabled'", $row);
        $this->assertStringContainsString("'disabled'", $row);
    }

    public function testForeignKeysExist(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('070_create_module_audit_table.sql');

        $fks = $this->foreignKeysOf('module_audit');
        $this->assertContains('fk_ma_tenant', $fks);
        $this->assertContains('fk_ma_actor', $fks);
    }

    public function testReasonColumnIsNullable(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('070_create_module_audit_table.sql');

        $row = $this->pdo()->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_audit' AND COLUMN_NAME = 'reason'"
        )?->fetchColumn();

        $this->assertSame('YES', $row);
    }
}
