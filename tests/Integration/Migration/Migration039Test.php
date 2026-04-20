<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration039Test extends MigrationTestCase
{
    public function test_user_invites_table_created(): void
    {
        $this->runMigrationsUpTo(38);
        $this->runMigration('039_create_user_invites.sql');

        $cols = $this->columnsOf('user_invites');
        self::assertContains('id', $cols);
        self::assertContains('user_id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('token_hash', $cols);
        self::assertContains('issued_at', $cols);
        self::assertContains('expires_at', $cols);
        self::assertContains('used_at', $cols);

        $fks = $this->foreignKeysOf('user_invites');
        self::assertContains('fk_user_invites_user', $fks);
        self::assertContains('fk_user_invites_tenant', $fks);
    }
}
