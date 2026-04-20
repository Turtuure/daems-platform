<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration050Test extends MigrationTestCase
{
    public function test_app_id_widened_to_varchar_64(): void
    {
        $this->runMigrationsUpTo(49);
        $this->runMigration('050_widen_app_id_for_compound_forum_report.sql');

        $type = (string) $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_id'"
        )?->fetchColumn();

        self::assertStringContainsString('varchar(64)', strtolower($type));
    }

    public function test_app_id_length_accepts_compound_forum_report_id(): void
    {
        $this->runMigrationsUpTo(49);
        $this->runMigration('050_widen_app_id_for_compound_forum_report.sql');

        // 'post:' + 36-char UUID = 41 chars. Column must be at least that wide.
        $maxLen = (int) $this->pdo->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_id'"
        )?->fetchColumn();

        self::assertGreaterThanOrEqual(42, $maxLen, 'app_id must fit "topic:<uuid-36>" (42 chars)');
    }
}
