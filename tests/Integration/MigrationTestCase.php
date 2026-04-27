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
        // when their parent module is present on disk. Each module file maps
        // back to a core sequence slot via $this->moduleOriginalSlots() so
        // mixed migrations (e.g. core 053 referencing both events_i18n +
        // projects_i18n) see required tables already created.
        $moduleFiles = glob(__DIR__ . '/../../../modules/*/backend/migrations/*.{sql,php}', GLOB_BRACE) ?: [];

        $slotMap = $this->moduleOriginalSlots();

        // Track which core slots are still occupied (i.e. the original
        // NNN_*.sql file is still present in database/migrations/). When a
        // module migration maps to a still-occupied slot we SKIP it: the
        // core copy is canonical until extraction is finished and the core
        // file is deleted (cf. Phase 1 mid-flight modules like Events).
        $occupiedCoreSlots = [];
        foreach ($coreFiles as $file) {
            if (preg_match('/^(\d{3})_/', basename($file), $m) === 1) {
                $occupiedCoreSlots[(int) $m[1]] = true;
            }
        }

        // Sort key: float NNN.subseq. Core uses exact NNN, modules use their
        // ORIGINAL pre-extraction NNN looked up from slotMap. Ties preserve
        // basename order via stable secondary key.
        /** @var list<array{key:float,basename:string,path:string}> $entries */
        $entries = [];
        foreach ($coreFiles as $file) {
            $basename = basename($file);
            if (preg_match('/^(\d{3})_/', $basename, $m) === 1 && (int) $m[1] <= $highestNumber) {
                $entries[] = ['key' => (float) (int) $m[1], 'basename' => $basename, 'path' => $file];
            }
        }
        foreach ($moduleFiles as $file) {
            $basename = basename($file);
            if (isset($slotMap[$basename])) {
                $originalSlot = (int) $slotMap[$basename];
                // Core original still present → skip the module duplicate.
                // Once the core file is deleted (extraction completes) the
                // module migration takes over its slot.
                if (isset($occupiedCoreSlots[$originalSlot])) {
                    continue;
                }
                // Mapped: use original slot. Subseq 0.5 keeps module migrations
                // grouped right after the core slot they replaced (the core
                // file is gone, so 0.5 just orders deterministically among
                // siblings sharing the same slot).
                $entries[] = ['key' => $originalSlot + 0.5, 'basename' => $basename, 'path' => $file];
            } else {
                // Unmapped module migration (e.g. forum_*, insights_*): place
                // after all core migrations using a high pseudo-slot derived
                // from the module-local NNN. Core max is well under 999.
                if (preg_match('/_(\d{3})_/', $basename, $m) === 1) {
                    $entries[] = ['key' => 1000.0 + (int) $m[1], 'basename' => $basename, 'path' => $file];
                } else {
                    $entries[] = ['key' => 9999.0, 'basename' => $basename, 'path' => $file];
                }
            }
        }
        usort($entries, static function (array $a, array $b): int {
            return $a['key'] <=> $b['key'] ?: strcmp($a['basename'], $b['basename']);
        });
        foreach ($entries as $entry) {
            $this->runMigration($entry['basename']);
        }
    }

    /**
     * Map of <module-migration-basename> => original core slot number.
     *
     * Built by parsing the data-fix migrations in database/migrations/ that
     * rename rows in schema_migrations after a module move. Lines look like:
     *   UPDATE schema_migrations SET filename = 'project_001_create_projects_table.sql'
     *   WHERE filename = '003_create_projects_table.sql'
     *
     * The 003 is the original slot; the 'project_001_*' is the new module
     * filename. The map lets runMigrationsUpTo() interleave module migrations
     * back into their original core position so mixed migrations (e.g. 053
     * referencing both events + projects tables) see required tables.
     *
     * @return array<string,float>
     */
    private function moduleOriginalSlots(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cache = [];
        $renames = glob(__DIR__ . '/../../database/migrations/*_rename_*_in_schema_migrations_table.sql') ?: [];
        // Accept either column name: early rename SQLs used `migration`, later
        // ones use `filename` (the actual schema_migrations column). Quotes
        // may be ' or '' (escaped inside a literal string) — match both.
        $pattern = "/SET\\s+(?:filename|migration)\\s*=\\s*'{1,2}([^']+)'{1,2}\\s+WHERE\\s+(?:filename|migration)\\s*=\\s*'{1,2}(\\d{3})_/i";
        foreach ($renames as $file) {
            $sql = (string) file_get_contents($file);
            if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $m) {
                    $cache[$m[1]] = (float) (int) $m[2];
                }
            }
        }
        return $cache;
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
