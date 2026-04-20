<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration049Test extends MigrationTestCase
{
    public function test_forum_report_added_to_app_type_enum(): void
    {
        $this->runMigrationsUpTo(48);
        $this->runMigration('049_extend_dismissals_enum_forum_report.sql');

        $type = (string) $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_type'"
        )?->fetchColumn();
        foreach (['member', 'supporter', 'project_proposal', 'forum_report'] as $v) {
            self::assertStringContainsString($v, $type);
        }
    }
}
