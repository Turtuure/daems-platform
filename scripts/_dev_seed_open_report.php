<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4',
    'root',
    'salasana',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$daemsTenant = '01958000-0000-7000-8000-000000000001';

$post = $pdo->query("SELECT id FROM forum_posts WHERE tenant_id = '{$daemsTenant}' LIMIT 1")->fetchColumn();
if (!$post) {
    echo "No forum posts in daems tenant — cannot seed report.\n";
    exit(1);
}

$reporter = $pdo->query("SELECT id FROM users WHERE email = 'playwright-user@dev.local' LIMIT 1")->fetchColumn();
if (!$reporter) {
    echo "playwright-user@dev.local not found — run seed_playwright_users first.\n";
    exit(1);
}

// UUID7
$ms = (int) (microtime(true) * 1000);
$t = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
$r = bin2hex(random_bytes(10));
$var = dechex(0x8 | (hexdec($r[3]) & 0x3));
$id = sprintf('%s-%s-%s-%s-%s',
    substr($t, 0, 8), substr($t, 8, 4),
    '7' . substr($r, 0, 3),
    $var . substr($r, 4, 3),
    substr($r, 8, 12));

$pdo->prepare(
    "INSERT INTO forum_reports
        (id, tenant_id, target_type, target_id, reporter_user_id,
         reason_category, reason_detail, status, created_at)
     VALUES (?, ?, 'post', ?, ?, 'spam', 'Playwright seeded open report', 'open', NOW())
     ON DUPLICATE KEY UPDATE status='open', resolved_at=NULL"
)->execute([$id, $daemsTenant, $post, $reporter]);

echo "Seeded open report {$id} targeting post {$post} by reporter {$reporter}\n";
