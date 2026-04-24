<?php
declare(strict_types=1);

$pdo = new PDO('mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4', 'root', 'salasana', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = 'UPDATE forum_topics ft
           SET first_post_search_text = (
             SELECT content FROM forum_posts p
              WHERE p.topic_id = ft.id
              ORDER BY p.sort_order ASC, p.created_at ASC
              LIMIT 1
           )
         WHERE ft.first_post_search_text IS NULL';
$affected = $pdo->exec($sql);
echo "Backfilled {$affected} topics\n";
