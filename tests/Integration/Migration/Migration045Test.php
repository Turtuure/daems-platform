<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration045Test extends MigrationTestCase
{
    public function test_dismissals_enum_now_includes_project_proposal(): void
    {
        $this->runMigrationsUpTo(44);
        $this->runMigration('045_extend_dismissals_enum_and_comment_audit.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_type'"
        );
        $type = (string) ($stmt?->fetchColumn() ?? '');
        self::assertStringContainsString("'project_proposal'", $type);
    }

    public function test_comment_moderation_audit_table_created(): void
    {
        $this->runMigrationsUpTo(44);
        $this->runMigration('045_extend_dismissals_enum_and_comment_audit.sql');

        $cols = $this->columnsOf('project_comment_moderation_audit');
        self::assertContains('id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('project_id', $cols);
        self::assertContains('comment_id', $cols);
        self::assertContains('action', $cols);
        self::assertContains('reason', $cols);
        self::assertContains('performed_by', $cols);
        self::assertContains('created_at', $cols);

        $fks = $this->foreignKeysOf('project_comment_moderation_audit');
        self::assertContains('fk_pcma_tenant', $fks);
    }
}
