<?php

declare(strict_types=1);

/**
 * Dev-only seeder: upserts two fixed-password accounts used by the
 * Playwright e2e suite in tests/e2e/forum-moderation.spec.ts.
 *
 *   playwright-admin@dev.local  → is_platform_admin = 1
 *   playwright-user@dev.local   → regular member in daems tenant
 *
 * Password for both: "Playwright-Dev-Test-2026!"
 *
 * Re-run is idempotent — updates password + role on existing rows.
 * Do NOT run against production.
 */

$PASSWORD = 'Playwright-Dev-Test-2026!';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4',
    'root',
    'salasana',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$daemsTenant = '01958000-0000-7000-8000-000000000001';
$hash = password_hash($PASSWORD, PASSWORD_BCRYPT);

function upsertUser(PDO $pdo, string $email, string $name, string $hash, bool $platformAdmin): string
{
    $row = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $row->execute([$email]);
    $id = (string) ($row->fetchColumn() ?: '');
    if ($id !== '') {
        $u = $pdo->prepare('UPDATE users SET password_hash = ?, is_platform_admin = ?, deleted_at = NULL WHERE id = ?');
        $u->execute([$hash, $platformAdmin ? 1 : 0, $id]);
        echo "  updated: {$email} (id={$id})\n";
        return $id;
    }
    // UUID7: 48-bit ms timestamp + version 7 + variant 10xx + random bits.
    $ms = (int) (microtime(true) * 1000);
    $timeHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
    $rand = bin2hex(random_bytes(10));
    $g3 = '7' . substr($rand, 0, 3);
    $variantNibble = dechex(0x8 | (hexdec($rand[3]) & 0x3));
    $g4 = $variantNibble . substr($rand, 4, 3);
    $g5 = substr($rand, 8, 12);
    $id = sprintf('%s-%s-%s-%s-%s',
        substr($timeHex, 0, 8), substr($timeHex, 8, 4), $g3, $g4, $g5);
    $ins = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$id, $name, $email, $hash, '1990-01-01', $platformAdmin ? 1 : 0]);
    echo "  inserted: {$email} (id={$id})\n";
    return $id;
}

function enrollInTenant(PDO $pdo, string $userId, string $tenantId, string $role): void
{
    // Composite PK (user_id, tenant_id). ON DUPLICATE KEY to upsert role.
    $stmt = $pdo->prepare(
        'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = NULL'
    );
    $stmt->execute([$userId, $tenantId, $role]);
    echo "  enrolled in {$tenantId} as {$role}\n";
}

echo "Seeding Playwright users in daems_db\n";
$adminId = upsertUser($pdo, 'playwright-admin@dev.local', 'Playwright Admin', $hash, true);
enrollInTenant($pdo, $adminId, $daemsTenant, 'admin');

$userId = upsertUser($pdo, 'playwright-user@dev.local', 'Playwright User', $hash, false);
enrollInTenant($pdo, $userId, $daemsTenant, 'member');

echo "\nCredentials for Playwright:\n";
echo "  TEST_ADMIN_EMAIL=playwright-admin@dev.local\n";
echo "  TEST_ADMIN_PASSWORD={$PASSWORD}\n";
echo "  TEST_USER_EMAIL=playwright-user@dev.local\n";
echo "  TEST_USER_PASSWORD={$PASSWORD}\n";
