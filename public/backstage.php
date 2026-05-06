<?php

declare(strict_types=1);

/**
 * Backstage front controller — serves /backstage/* HTML and /api/backstage/* JSON.
 *
 * The API kernel at public/index.php handles /api/v1/*. This controller is a
 * separate process; it does NOT instantiate the platform Kernel/Router. It
 * uses curl-via-ApiClient to talk to the API kernel on the same host (single
 * extra localhost hop per page). Sessions and CSRF live here.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
// Daems\Frontend\* loaded via composer PSR-4 (registered in composer.json by Task 3)

define('DAEMS_BACKSTAGE_PUBLIC', __DIR__ . '/backstage');
// Modules expect this constant; point it at the backstage public dir so
// module backstage files like layout.php require paths resolve correctly.
define('DAEMS_SITE_PUBLIC', __DIR__ . '/backstage');

session_start();

if (class_exists(\Daems\Frontend\I18n::class, false)) {
    \Daems\Frontend\I18n::locale();
}

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');

// Static asset passthrough — Apache also serves these directly via -f rule
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        return false;
    }
}

// modules/shared/* → /modules-shared/* (DatePicker, TimePicker, KpiCard, etc.)
if (str_starts_with($uri, '/modules-shared/')) {
    $rel = substr($uri, strlen('/modules-shared/'));
    $base = realpath(dirname(__DIR__, 2) . '/modules/shared');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                 'png' => 'image/png', 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// modules/<name>/frontend/assets/* → /modules/<name>/assets/*
if (preg_match('#^/modules/([a-z][a-z0-9-]*)/assets/(.+)$#', $uri, $m)) {
    $module = $m[1];
    $rel = $m[2];
    $base = realpath(dirname(__DIR__, 2) . '/modules/' . $module . '/frontend/assets');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// Logout
if ($uri === '/backstage/logout') {
    require __DIR__ . '/backstage/auth/logout.php';
    exit;
}

// Login (form + handler)
if ($uri === '/backstage/login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require __DIR__ . '/backstage/auth/login-handler.php';
    } else {
        require __DIR__ . '/backstage/auth/login.php';
    }
    exit;
}

// Backstage HTML routes
if (str_starts_with($uri, '/backstage')) {
    require __DIR__ . '/backstage/router.php';
    exit;
}

// Backstage JSON proxy routes
if (str_starts_with($uri, '/api/backstage/')) {
    require __DIR__ . '/backstage/api-router.php';
    exit;
}

http_response_code(404);
echo 'Not found';
