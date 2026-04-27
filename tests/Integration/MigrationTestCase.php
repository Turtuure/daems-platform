<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class MigrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $db   = getenv('TEST_DB_NAME') ?: 'daems_db_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: 'salasana';

        $this->pdo = new PDO(
            "mysql:host={$host};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->resetDatabase();
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    protected function runMigration(string $filename): void
    {
        // Resolve filename: core migrations live in database/migrations/; module
        // migrations (named <module>_NNN_<desc>.{sql,php}) live in
        // ../modules/<module>/backend/migrations/.
        $path = __DIR__ . '/../../database/migrations/' . $filename;
        if (!is_file($path)) {
            $modulesDir = __DIR__ . '/../../../modules';
            foreach ((array) glob($modulesDir . '/*/backend/migrations/' . $filename) as $candidate) {
                if (is_string($candidate) && is_file($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        }
        if (str_ends_with($filename, '.php')) {
            // .php migrations are self-contained scripts that handle their own
            // PDO connection — execute via require, not as raw SQL.
            $pdo = $this->pdo;
            require $path;
            return;
        }
        $sql  = (string) file_get_contents($path);

        // Split on `;` at end of line; skip `--` comments and empty lines
        $statements = preg_split('/;[\r\n]+/', $sql);
        if ($statements === false) {
            return;
        }

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            // Remove -- comment-only content
            $lines = array_filter(
                preg_split('/\r?\n/', $stmt) ?: [],
                static fn (string $l): bool => !str_starts_with(trim($l), '--') && trim($l) !== ''
            );
            $clean = implode("\n", $lines);
            if (trim($clean) === '') {
                continue;
            }
            $this->pdo->exec($clean);
        }
    }

    protected function runMigrationsUpTo(int $highestNumber): void
    {
        // Core migrations: <NNN>_<desc>.{sql,php}, gated by $highestNumber.
        $coreFiles = glob(__DIR__ . '/../../database/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
        // Module migrations: <module>_<NNN>_<desc>.{sql,php}, always included
        // when their parent module is present on disk (their numbering is
        // independent of the core sequence).
        $moduleFiles = glob(__DIR__ . '/../../../modules/*/backend/migrations/*.{sql,php}', GLOB_BRACE) ?: [];

        $all = [];
        foreach ($coreFiles as $file) {
            $basename = basename($file);
            if (preg_match('/^(\d{3})_/', $basename, $m) === 1 && (int) $m[1] <= $highestNumber) {
                $all[$basename] = $file;
            }
        }
        foreach ($moduleFiles as $file) {
            $all[basename($file)] = $file;
        }
        ksort($all);
        foreach ($all as $basename => $_file) {
            $this->runMigration($basename);
        }
    }

    /** @return list<string> */
    protected function columnsOf(string $table): array
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($rows as $row) {
            if (isset($row['Field']) && is_string($row['Field'])) {
                $names[] = $row['Field'];
            }
        }
        return $names;
    }

    /** @return list<string> */
    protected function foreignKeysOf(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['CONSTRAINT_NAME']) && is_string($row['CONSTRAINT_NAME'])) {
                $names[] = $row['CONSTRAINT_NAME'];
            }
        }
        return $names;
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->pdo->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $table) {
            if (is_string($table)) {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        $triggers = $this->pdo->query('SHOW TRIGGERS')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($triggers as $trg) {
            if (isset($trg['Trigger']) && is_string($trg['Trigger'])) {
                $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trg['Trigger']}`");
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
