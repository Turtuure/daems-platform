<?php
declare(strict_types=1);

http_response_code(200);
echo '<!doctype html><meta charset=utf-8><title>Backstage (WIP)</title>';
echo '<h1>Backstage migration in progress</h1>';
echo '<p>Wave A scaffold reached. URI: ' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) . '</p>';
