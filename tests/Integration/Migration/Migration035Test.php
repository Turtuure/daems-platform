<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration035Test extends MigrationTestCase
{
    public function test_creates_member_status_audit_table(): void
    {
        $this->runMigrationsUpTo(34);
        $this->runMigration('035_create_member_status_audit.sql');

        $cols = $this->columnsOf('member_status_audit');
        self::assertContains('id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('user_id', $cols);
        self::assertContains('previous_status', $cols);
        self::assertContains('new_status', $cols);
        self::assertContains('reason', $cols);
        self::assertContains('performed_by', $cols);
        self::assertContains('created_at', $cols);
    }

    public function test_fk_constraints_present(): void
    {
        $this->runMigrationsUpTo(34);
        $this->runMigration('035_create_member_status_audit.sql');

        $fks = $this->foreignKeysOf('member_status_audit');
        self::assertContains('fk_member_status_audit_tenant', $fks);
        self::assertContains('fk_member_status_audit_user', $fks);
        self::assertContains('fk_member_status_audit_performer', $fks);
    }
}
