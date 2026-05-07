<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Daems\Infrastructure\Framework\Http\Request;

$kernel = require dirname(__DIR__) . '/bootstrap/app.php';

/* ------------------------------------------------------------------ */
/* Public-site delegation (Wave I4).                                   */
/* ------------------------------------------------------------------ */
//
// Non-API requests (anything not under /api/) belong to the public site,
// not the API kernel. Hand them off to public/sites-router.php which
// decides whether to delegate to a tenant's custom frontend or fall back
// to the bundled default site at public/sites/_default/.
//
// /backstage* and /api/backstage/* are routed by .htaccess to backstage.php
// before reaching this file, so we don't need to filter them here.

$__path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (!str_starts_with($__path, '/api/')) {
    $GLOBALS['_daems_kernel'] = $kernel;
    require __DIR__ . '/sites-router.php';
    exit;
}

$kernel->send(
    $kernel->handle(Request::fromGlobals())
);
