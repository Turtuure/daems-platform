<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';
require_once __DIR__ . '/pages/_shared.php';

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');
$daemsKnownModules = $GLOBALS['daemsKnownModules'] ?? [];

// Module page router — /backstage/<module>/<sub-path>?
if (preg_match('#^/backstage/([a-z][a-z0-9-]*)(/.*)?$#', $uri, $m)
    && isset($daemsKnownModules[$m[1]])
    && $daemsKnownModules[$m[1]]['backstage'] !== null
) {
    $module = $m[1];
    $sub = ltrim($m[2] ?? '', '/');
    $base = realpath($daemsKnownModules[$module]['backstage']);
    if ($base !== false) {
        $candidates = [];
        if ($sub === '') {
            $candidates[] = $base . '/index.php';
        } else {
            $candidates[] = $base . '/' . $sub . '/index.php';
            $candidates[] = $base . '/' . $sub . '.php';
        }
        foreach ($candidates as $cand) {
            $real = realpath($cand);
            if ($real !== false && str_starts_with($real, $base) && is_file($real)) {
                require $real;
                exit;
            }
        }
    }
}

$sub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');

// Legacy /backstage/applications redirect — members module owns the file (Wave F migration).
if ($sub === '/applications' && isset($daemsKnownModules['members']['backstage'])) {
    $appsFile = $daemsKnownModules['members']['backstage'] . '/applications/index.php';
    if (is_file($appsFile)) {
        require $appsFile;
        exit;
    }
}

$map = [
    ''                   => __DIR__ . '/pages/index.php',
    '/notifications'     => __DIR__ . '/pages/notifications/index.php',
    '/search'            => __DIR__ . '/pages/search/index.php',
    '/settings'          => __DIR__ . '/pages/settings/index.php',
    '/project-proposals' => __DIR__ . '/pages/project-proposals/index.php',
];

if (isset($map[$sub]) && is_file($map[$sub])) {
    require $map[$sub];
    exit;
}

http_response_code(404);
echo 'Not found';
