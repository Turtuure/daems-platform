<?php

declare(strict_types=1);

/**
 * Wave H — proxy for the tenant-scoped Module Activation API.
 *
 * Used by /backstage/settings/modules so a tenant admin can enable/disable
 * the modules their plan grants them. Authorisation is enforced by the
 * platform; the proxy only checks that the caller is at least an admin.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
$role = is_array($u) ? (string) ($u['role'] ?? '') : '';
$ok   = is_array($u) && (
    !empty($u['is_platform_admin'])
    || $role === 'admin'
    || $role === 'global_system_administrator'
);
if (!$ok) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$op   = (string) ($_GET['op'] ?? '');
$slug = (string) ($_GET['slug'] ?? '');
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) { $body = []; }

try {
    switch ($op) {
        case 'list':
            $r = ApiClient::get('/backstage/tenant/modules');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'state':
            if ($slug === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_slug']);
                return;
            }
            $r = ApiClient::post('/backstage/tenant/modules/' . rawurlencode($slug) . '/state', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
