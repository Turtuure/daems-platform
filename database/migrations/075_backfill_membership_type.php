<?php
declare(strict_types=1);

/**
 * 075_backfill_membership_type.php
 * Normalise users.membership_type to the four canonical values defined by
 * Daem Society bylaws § 3: SUPPORTING, BASIC, FULL, HONORARY.
 * Old value is preserved in users.membership_type_legacy for audit.
 *
 * The script is wrapped in one transaction so a partial-deploy failure does
 * not leave the column half-normalised.
 *
 * Two execution modes:
 *   - Test mode: MigrationTestCase passes $pdo in scope via require.
 *   - Standalone: `php database/migrations/075_backfill_membership_type.php`
 *     creates its own PDO from .env credentials.
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

$pdo->beginTransaction();
try {
    // 1. Audit copy — preserve old value where not already saved.
    $pdo->exec(
        "UPDATE users
            SET membership_type_legacy = membership_type
          WHERE membership_type_legacy IS NULL"
    );

    // 2. Normalise to the four canonical values.
    $pdo->exec("UPDATE users SET membership_type = 'SUPPORTING' WHERE membership_type = 'supporter'");
    $pdo->exec("UPDATE users SET membership_type = 'BASIC'      WHERE membership_type IN ('basic', 'individual')");
    $pdo->exec("UPDATE users SET membership_type = 'FULL'       WHERE membership_type = 'full'");
    $pdo->exec("UPDATE users SET membership_type = 'HONORARY'   WHERE membership_type = 'honorary'");

    // 3. Fill membership_started_at where NULL — uses users.created_at as the
    // proxy join date. Required for the 12-month BASIC→FULL eligibility rule
    // implemented in milestone 0.6b.
    $pdo->exec(
        "UPDATE users
            SET membership_started_at = created_at
          WHERE membership_started_at IS NULL
            AND membership_type IN ('BASIC', 'FULL', 'HONORARY')"
    );

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
