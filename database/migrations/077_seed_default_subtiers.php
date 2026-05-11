<?php
declare(strict_types=1);

/**
 * 077_seed_default_subtiers.php
 * Seed bronze/silver/gold/platinum × SUPPORTING + BASIC for every existing
 * tenant. INSERT IGNORE keeps the script idempotent on re-run.
 *
 * Test mode: MigrationTestCase passes $pdo via require.
 * Standalone: `php database/migrations/077_seed_default_subtiers.php`.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../../vendor/autoload.php';
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

$defaults = [
    ['bronze',   'Bronze',   1],
    ['silver',   'Silver',   2],
    ['gold',     'Gold',     3],
    ['platinum', 'Platinum', 4],
];
$appliesToValues = ['SUPPORTING', 'BASIC'];

$tenantIds = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
if (!is_array($tenantIds)) {
    throw new RuntimeException('Failed to read tenants for sub-tier seed');
}

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_membership_subtiers
        (id, tenant_id, slug, name, rank_order, applies_to)
     VALUES (?, ?, ?, ?, ?, ?)'
);

foreach ($tenantIds as $tenantId) {
    if (!is_string($tenantId)) continue;
    foreach ($appliesToValues as $appliesTo) {
        foreach ($defaults as [$slug, $name, $rank]) {
            $insert->execute([
                \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
                $tenantId, $slug, $name, $rank, $appliesTo,
            ]);
        }
    }
}
