<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');

// Some module-side JS still hits the legacy society URL form
// `/api/backstage/<resource>.php?op=...`. Strip the `.php` so both forms resolve
// to the same proxy file post-migration.
if (str_ends_with($uri, '.php')) {
    $uri = substr($uri, 0, -4);
}

$map = [
    '/api/backstage/applications'    => __DIR__ . '/api/applications.php',
    '/api/backstage/members'         => __DIR__ . '/api/members.php',
    '/api/backstage/notifications'   => __DIR__ . '/api/notifications.php',
    '/api/backstage/search'          => __DIR__ . '/api/search.php',
    '/api/backstage/dismiss'         => __DIR__ . '/api/dismiss.php',
    '/api/backstage/events'          => __DIR__ . '/api/events.php',
    '/api/backstage/projects'        => __DIR__ . '/api/projects.php',
    '/api/backstage/proposals'       => __DIR__ . '/api/proposals.php',
    '/api/backstage/event-upload'    => __DIR__ . '/api/event-upload.php',
    '/api/backstage/forum'           => __DIR__ . '/api/forum.php',
    '/api/backstage/insights'        => __DIR__ . '/api/insights.php',
    '/api/backstage/tenant-settings' => __DIR__ . '/api/tenant-settings.php',
];

if (isset($map[$uri]) && is_file($map[$uri])) {
    require $map[$uri];
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown_proxy', 'uri' => $uri]);
