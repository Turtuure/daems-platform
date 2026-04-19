<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration024Test extends MigrationTestCase
{
    public function testRoleColumnDropped(): void
    {
        $this->runMigrationsUpTo(23);
        $this->runMigration('024_drop_users_role_column.sql');

        $this->assertNotContains('role', $this->columnsOf('users'));
    }

    public function testIsPlatformAdminColumnRetained(): void
    {
        $this->runMigrationsUpTo(23);
        $this->runMigration('024_drop_users_role_column.sql');

        $this->assertContains('is_platform_admin', $this->columnsOf('users'));
    }
}
