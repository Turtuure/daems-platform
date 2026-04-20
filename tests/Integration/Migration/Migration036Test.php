<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration036Test extends MigrationTestCase
{
    public function test_password_hash_becomes_nullable(): void
    {
        $this->runMigrationsUpTo(35);
        $this->runMigration('036_make_users_password_hash_nullable.sql');

        $stmt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'password_hash'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('YES', $row['IS_NULLABLE']);
    }
}
