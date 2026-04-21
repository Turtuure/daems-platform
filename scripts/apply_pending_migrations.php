<?php

declare(strict_types=1);

// One-off dev helper: list migrations 001–NNN, check which are already applied
// by inspecting expected schema, and apply the rest.
// Uses the migrations table pattern if available; falls back to heuristic checks.

$host = '127.0.0.1';
$db   = 'daems_db';
$user = 'root';
$pass = 'salasana';

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

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files);

$runOne = static function (PDO $pdo, string $file): void {
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

$ranAny = false;
foreach ($files as $file) {
    $base = basename($file);
    if (isset($applied[$base])) {
        continue;
    }
    echo "Applying {$base} … ";
    try {
        $runOne($pdo, $file);
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
