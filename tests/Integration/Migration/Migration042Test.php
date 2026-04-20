<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration042Test extends MigrationTestCase
{
    public function test_deleted_at_column_added_and_is_nullable(): void
    {
        $this->runMigrationsUpTo(41);
        $this->runMigration('042_add_deleted_at_to_users.sql');

        $stmt = $this->pdo->query(
            "SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'deleted_at'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('YES', $row['IS_NULLABLE']);
        self::assertSame('datetime', strtolower((string) $row['DATA_TYPE']));
    }
}
