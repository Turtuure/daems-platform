<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration048Test extends MigrationTestCase
{
    public function test_all_three_tables_plus_edited_at_created(): void
    {
        $this->runMigrationsUpTo(47);
        $this->runMigration('048_create_forum_reports_audit_warnings_and_edited_at.sql');

        foreach (['forum_reports', 'forum_moderation_audit', 'forum_user_warnings'] as $table) {
            $exists = $this->pdo->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"
            )?->fetchColumn();
            self::assertSame('1', (string) $exists, "$table should exist");
        }

        $editedAt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forum_posts'
               AND COLUMN_NAME = 'edited_at'"
        )?->fetchColumn();
        self::assertSame('YES', (string) $editedAt);
    }

    public function test_forum_reports_unique_constraint_on_reporter_target(): void
    {
        $this->runMigrationsUpTo(47);
        $this->runMigration('048_create_forum_reports_audit_warnings_and_edited_at.sql');

        $idx = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forum_reports'
               AND INDEX_NAME = 'uq_reporter_target'"
        )?->fetchColumn();
        self::assertSame(3, (int) $idx, 'unique index covers 3 columns');
    }
}
