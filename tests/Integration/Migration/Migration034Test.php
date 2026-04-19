<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration034Test extends MigrationTestCase
{
    public function test_adds_decision_columns_to_member_applications(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $cols = $this->columnsOf('member_applications');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }

    public function test_adds_decision_columns_to_supporter_applications(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $cols = $this->columnsOf('supporter_applications');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }

    public function test_decider_fk_constraint_created(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $fks = $this->foreignKeysOf('member_applications');
        self::assertContains('fk_member_applications_decider', $fks);
    }
}
