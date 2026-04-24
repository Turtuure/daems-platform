<?php
// One-off backfill — strips HTML from insights.content into insights.search_text.
// Runtime writes go through SqlInsightRepository::save() instead (task 15).
declare(strict_types=1);

$pdo = new PDO('mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4', 'root', 'salasana', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rows = $pdo->query('SELECT id, content FROM insights WHERE search_text IS NULL')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('UPDATE insights SET search_text = ? WHERE id = ?');
foreach ($rows as $r) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $r['content'])) ?? '');
    $stmt->execute([$plain, $r['id']]);
}
echo "Backfilled " . count($rows) . " rows\n";
