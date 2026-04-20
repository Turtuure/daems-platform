<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration037Test extends MigrationTestCase
{
    public function test_date_of_birth_becomes_nullable(): void
    {
        $this->runMigrationsUpTo(36);
        $this->runMigration('037_make_users_date_of_birth_nullable.sql');

        $stmt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'date_of_birth'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('YES', $row['IS_NULLABLE']);
    }
}
