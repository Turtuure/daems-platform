<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';

http_response_code(200);
echo '<!doctype html><meta charset=utf-8><title>Backstage (WIP)</title>';
echo '<h1>Authenticated backstage scaffold</h1>';
echo '<p>User: ' . htmlspecialchars((string) ($_SESSION['user']['name'] ?? '?'), ENT_QUOTES) . '</p>';
echo '<p>URI: ' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) . '</p>';
