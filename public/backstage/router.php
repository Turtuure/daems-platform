<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require_once __DIR__ . '/pages/_shared.php';

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');
$sub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');

$map = [
    ''               => __DIR__ . '/pages/index.php',
    '/notifications' => __DIR__ . '/pages/notifications/index.php',
    '/search'        => __DIR__ . '/pages/search/index.php',
];

if (isset($map[$sub]) && is_file($map[$sub])) {
    require $map[$sub];
    exit;
}

http_response_code(404);
echo 'Not found';
