<?php
declare(strict_types=1);

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');

// Wave G2 — module route guard runs BEFORE auth so a tenant whose forum is
// disabled sees the same 404 as one whose forum doesn't exist. If no tenant
// resolves for this host (very rare — fallback map covers all dev hosts),
// we skip the guard and let the existing 404 path handle it.
[$__moduleGuard, $__tenantId] = require __DIR__ . '/_module-guard.php';
if ($__tenantId !== null) {
    $__path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($__path) || $__path === '') {
        $__path = '/';
    }
    if ($__moduleGuard->authorize($__tenantId, $__path) === \Daems\Domain\Tenant\ModuleRouteGuard::NOT_FOUND) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

require __DIR__ . '/_guard.php';
require_once __DIR__ . '/pages/_shared.php';

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
    ''                       => __DIR__ . '/pages/index.php',
    '/notifications'         => __DIR__ . '/pages/notifications/index.php',
    '/search'                => __DIR__ . '/pages/search/index.php',
    '/settings'              => __DIR__ . '/pages/settings/index.php',
    '/settings/modules'      => __DIR__ . '/pages/settings/modules/index.php',
    '/project-proposals'     => __DIR__ . '/pages/project-proposals/index.php',
    '/platform/tenants'      => __DIR__ . '/pages/platform/tenants/index.php',
    '/governance/board'              => __DIR__ . '/pages/governance/board.php',
    '/governance/billing'            => __DIR__ . '/pages/governance/billing.php',
    '/governance/billing/overrides'  => __DIR__ . '/pages/governance/billing-overrides.php',
    '/governance/billing/invoices'   => __DIR__ . '/pages/governance/billing-invoices.php',
    '/governance/decisions'          => __DIR__ . '/pages/governance/decisions-list.php',
    '/governance/decisions/new'      => __DIR__ . '/pages/governance/decisions-new.php',
    '/governance/decisions/detail'   => __DIR__ . '/pages/governance/decisions-detail.php',
    '/governance/expulsions'         => __DIR__ . '/pages/governance/expulsions-list.php',
    '/governance/expulsions/new'     => __DIR__ . '/pages/governance/expulsions-new.php',
    '/governance/expulsions/detail'  => __DIR__ . '/pages/governance/expulsions-detail.php',
    '/governance/delegations'        => __DIR__ . '/pages/governance/delegations.php',
    '/governance/settings'           => __DIR__ . '/pages/governance/settings.php',
];

if (isset($map[$sub]) && is_file($map[$sub])) {
    require $map[$sub];
    exit;
}

// Wave H — platform tenant edit shell. /backstage/platform/tenants/<id> →
// the edit shell (5 tabs dispatched via ?tab=). The router lacks regex
// segment support, so we match the prefix manually and forward the trailing
// id as $tenantIdParam to the page.
if (preg_match('#^/platform/tenants/([A-Za-z0-9_\-]+)$#', $sub, $tm)) {
    $tenantIdParam = $tm[1];
    require __DIR__ . '/pages/platform/tenants/edit.php';
    exit;
}

http_response_code(404);
echo 'Not found';
