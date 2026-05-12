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

// Wave G2 — module route guard. Same rationale as the page router: we want
// disabled-module endpoints to look identical to non-existent endpoints
// regardless of auth state. The guard answers ALLOW for unowned paths
// (shell + non-module endpoints), so the existing $map dispatch is unchanged
// for those.
[$__moduleGuard, $__tenantId] = require __DIR__ . '/_module-guard.php';
if ($__tenantId !== null) {
    $__path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($__path) || $__path === '') {
        $__path = '/';
    }
    // Strip the same legacy `.php` suffix so the guard sees the canonical path.
    if (str_ends_with($__path, '.php')) {
        $__path = substr($__path, 0, -4);
    }
    if ($__moduleGuard->authorize($__tenantId, $__path) === \Daems\Domain\Tenant\ModuleRouteGuard::NOT_FOUND) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_found']);
        exit;
    }
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
    '/api/backstage/platform-tenants' => __DIR__ . '/api/platform-tenants.php',
    '/api/backstage/tenant-modules'   => __DIR__ . '/api/tenant-modules.php',
    '/api/backstage/dashboard/layout' => __DIR__ . '/api/dashboard.php',
    '/api/backstage/dashboard/catalog' => __DIR__ . '/api/dashboard.php',
    '/api/backstage/governance/board'                         => __DIR__ . '/api/governance-board.php',
    '/api/backstage/governance/board/bootstrap'               => __DIR__ . '/api/governance-board.php',
    '/api/backstage/governance/billing/fee-schedules'         => __DIR__ . '/api/governance-billing.php',
    '/api/backstage/governance/decisions'                     => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/approve-basic'       => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/invite-full'         => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/award-subtier'       => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/revoke-subtier'      => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/subtier-crud'        => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/remove-board-member' => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/delegate-authority'  => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/decisions/revoke-delegation'   => __DIR__ . '/api/governance-decisions.php',
    '/api/backstage/governance/expulsions'                       => __DIR__ . '/api/governance-expulsions.php',
    '/api/backstage/governance/delegations'                      => __DIR__ . '/api/governance-delegations.php',
    '/api/backstage/governance/eligibility/full-membership'      => __DIR__ . '/api/governance-eligibility.php',
    '/api/backstage/governance/gsa-overrides/approve-basic'      => __DIR__ . '/api/governance-gsa-overrides.php',
];

if (isset($map[$uri]) && is_file($map[$uri])) {
    require $map[$uri];
    exit;
}

// Pattern fallback for governance routes with IDs
if (str_starts_with($uri, '/api/backstage/governance/decisions/')) {
    require __DIR__ . '/api/governance-decisions.php';
    exit;
}

if (str_starts_with($uri, '/api/backstage/governance/expulsions/')) {
    require __DIR__ . '/api/governance-expulsions.php';
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown_proxy', 'uri' => $uri]);
