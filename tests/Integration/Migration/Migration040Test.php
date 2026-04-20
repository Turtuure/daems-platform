<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration040Test extends MigrationTestCase
{
    public function test_admin_application_dismissals_table_created(): void
    {
        $this->runMigrationsUpTo(39);
        $this->runMigration('040_create_admin_application_dismissals.sql');

        $cols = $this->columnsOf('admin_application_dismissals');
        self::assertContains('id', $cols);
        self::assertContains('admin_id', $cols);
        self::assertContains('app_id', $cols);
        self::assertContains('app_type', $cols);
        self::assertContains('dismissed_at', $cols);

        $fks = $this->foreignKeysOf('admin_application_dismissals');
        self::assertContains('fk_aad_admin', $fks);
    }
}
