<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');

$map = [
    '/api/backstage/applications'    => __DIR__ . '/api/applications.php',
    '/api/backstage/members'         => __DIR__ . '/api/members.php',
    '/api/backstage/notifications'   => __DIR__ . '/api/notifications.php',
    '/api/backstage/search'          => __DIR__ . '/api/search.php',
    '/api/backstage/dismiss'         => __DIR__ . '/api/dismiss.php',
    // Wave-D additions append below as proxies migrate.
];

if (isset($map[$uri]) && is_file($map[$uri])) {
    require $map[$uri];
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown_proxy', 'uri' => $uri]);
