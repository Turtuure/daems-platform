<?php
declare(strict_types=1);

/**
 * 089_backfill_membership_started_at_for_supporting.php
 * Fills SUPPORTING members' membership_started_at from users.created_at.
 * Mig 075 missed them; anniversary-cron (Wave C) needs this column populated
 * for SUPPORTING in addition to BASIC/FULL/HONORARY.
 *
 * Idempotent: WHERE membership_started_at IS NULL.
 *
 * Test mode: MigrationTestCase passes $pdo via require.
 * Standalone: `php database/migrations/089_backfill_membership_started_at_for_supporting.php`.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $envFile = __DIR__ . '/../../.env';
    $env = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $env[$k] = $v;
        }
    }
    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1')
            . ';port=' . ($env['DB_PORT'] ?? '3306')
            . ';dbname=' . ($env['DB_DATABASE'] ?? 'daems_db')
            . ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

$affected = $pdo->exec(
    "UPDATE users
        SET membership_started_at = created_at
      WHERE membership_started_at IS NULL
        AND membership_type = 'SUPPORTING'"
);
fwrite(STDOUT, "Backfilled {$affected} SUPPORTING users\n");
