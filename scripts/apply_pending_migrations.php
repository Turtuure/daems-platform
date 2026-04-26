<?php

declare(strict_types=1);

// Migration runner: scan core + module migration dirs, apply pending .sql/.php
// files, record by filename in schema_migrations.
//
// Filename ordering: alphabetical across ALL dirs. Module migrations are
// expected to be named '<module>_NNN_<desc>.{sql,php}' so they sort
// independently of core migrations (NNN_<desc>.{sql,php}).

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'daems_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'salasana';

$pdo = new PDO(
    "mysql:host={$host};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Ensure a tiny bookkeeping table exists.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// Discover migration dirs: core + each module's backend/migrations/.
$dirs = [__DIR__ . '/../database/migrations'];

// Boot the registry to fetch module dirs without going through full app bootstrap.
require __DIR__ . '/../vendor/autoload.php';
$registry = new \Daems\Infrastructure\Module\ModuleRegistry();
$registry->discover(__DIR__ . '/../../modules');
foreach ($registry->migrationPaths() as $modPath) {
    if (is_dir($modPath)) {
        $dirs[] = rtrim($modPath, '/\\');
    }
}

// Glob .sql and .php files in each dir, then sort the combined list alphabetically by basename.
$files = [];
foreach ($dirs as $dir) {
    foreach ((array) glob($dir . '/*.{sql,php}', GLOB_BRACE) as $f) {
        if (is_string($f)) {
            $files[] = $f;
        }
    }
}
usort($files, static fn(string $a, string $b): int => basename($a) <=> basename($b));

$runSql = static function (PDO $pdo, string $file): void {
    $sql = (string) file_get_contents($file);
    $statements = preg_split('/;[\r\n]+/', $sql) ?: [];
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $lines = array_filter(
            preg_split('/\r?\n/', $stmt) ?: [],
            static fn(string $l): bool => !str_starts_with(trim($l), '--') && trim($l) !== ''
        );
        $clean = implode("\n", $lines);
        if (trim($clean) !== '') {
            $pdo->exec($clean);
        }
    }
};

$runPhp = static function (PDO $pdo, string $file): void {
    // PHP migration files manage their own DB connection today (legacy form).
    // Standard contract going forward: file may either (a) connect itself, or
    // (b) reference a $pdo variable provided by the runner.
    require $file;
};

$ranAny = false;
foreach ($files as $file) {
    $base = basename($file);
    if (isset($applied[$base])) {
        continue;
    }
    echo "Applying {$base} … ";
    try {
        if (str_ends_with($file, '.sql')) {
            $runSql($pdo, $file);
        } else {
            $runPhp($pdo, $file);
        }
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$base]);
        echo "OK\n";
        $ranAny = true;
    } catch (PDOException $e) {
        // If the migration fails because objects already exist (older hand-applied DB),
        // we still record it so future runs stay in sync.
        $msg = $e->getMessage();
        $idempotentMarkers = [
            'already exists',
            'Duplicate column name',
            'Duplicate key name',
            "check that column/key exists",
        ];
        $isBenign = false;
        foreach ($idempotentMarkers as $m) {
            if (stripos($msg, $m) !== false) { $isBenign = true; break; }
        }
        if ($isBenign) {
            echo "ALREADY APPLIED (recording): {$msg}\n";
            $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute([$base]);
        } else {
            echo "FAILED\n";
            throw $e;
        }
    }
}

if (!$ranAny) {
    echo "No pending migrations.\n";
}
