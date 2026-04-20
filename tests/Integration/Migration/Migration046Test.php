<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration046Test extends MigrationTestCase
{
    public function test_decision_columns_added_to_project_proposals(): void
    {
        $this->runMigrationsUpTo(45);
        $this->runMigration('046_add_decision_metadata_to_project_proposals.sql');

        $cols = $this->columnsOf('project_proposals');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }
}
