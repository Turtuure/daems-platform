<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration044Test extends MigrationTestCase
{
    public function test_featured_column_added_as_tinyint_default_0(): void
    {
        $this->runMigrationsUpTo(43);
        $this->runMigration('044_add_featured_to_projects.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'projects'
               AND COLUMN_NAME = 'featured'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString('tinyint', strtolower((string) $row['COLUMN_TYPE']));
        self::assertSame('0', (string) $row['COLUMN_DEFAULT']);
        self::assertSame('NO', $row['IS_NULLABLE']);
    }
}
