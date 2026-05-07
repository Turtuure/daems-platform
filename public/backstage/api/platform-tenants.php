<?php

declare(strict_types=1);

/**
 * Wave H — proxy for the platform-side Tenant Management API.
 *
 * Forwards calls from the backstage UI (which lives on the tenant-frontend
 * host and so cannot call /api/v1/* directly with Bearer auth) to the
 * platform API endpoints introduced in Wave F.
 *
 * Authorisation: the proxy itself only checks that the caller is
 * platform-admin (GSA). The platform API performs the canonical authz check.
 *
 * Operations are dispatched via ?op=<name>. Request shapes mirror the
 * upstream API one-to-one — see docs/superpowers/specs for the contract.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
$isGsa = is_array($u) && (
    !empty($u['is_platform_admin'])
    || ($u['role'] ?? '') === 'global_system_administrator'
);
if (!$isGsa) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$op   = (string) ($_GET['op'] ?? '');
$id   = (string) ($_GET['id'] ?? '');
$did  = (string) ($_GET['did'] ?? '');
$uid  = (string) ($_GET['uid'] ?? '');
$slug = (string) ($_GET['slug'] ?? '');
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) { $body = []; }

/** @return never */
function bail(int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['error' => $error]);
    exit;
}

function need(string $what, string $val): void {
    if ($val === '') { bail(400, 'missing_' . $what); }
}

try {
    switch ($op) {
        // ---------- Tenants ----------
        case 'list':
            $r = ApiClient::get('/backstage/platform/tenants');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'create':
            $r = ApiClient::post('/backstage/platform/tenants', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'get':
            need('id', $id);
            $r = ApiClient::get('/backstage/platform/tenants/' . rawurlencode($id));
            echo json_encode(['data' => $r]);
            return;

        case 'patch':
            need('id', $id);
            $r = ApiClient::patch('/backstage/platform/tenants/' . rawurlencode($id), $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'suspend':
            need('id', $id);
            $r = ApiClient::post('/backstage/platform/tenants/' . rawurlencode($id) . '/suspend', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'reactivate':
            need('id', $id);
            $r = ApiClient::post('/backstage/platform/tenants/' . rawurlencode($id) . '/reactivate', []);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        // ---------- Domains ----------
        case 'domains.list':
            need('id', $id);
            $r = ApiClient::get('/backstage/platform/tenants/' . rawurlencode($id) . '/domains');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'domains.add':
            need('id', $id);
            $r = ApiClient::post('/backstage/platform/tenants/' . rawurlencode($id) . '/domains', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'domains.update':
            need('id', $id);
            need('did', $did);
            $r = ApiClient::patch('/backstage/platform/tenants/' . rawurlencode($id) . '/domains/' . rawurlencode($did), $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'domains.remove':
            need('id', $id);
            need('did', $did);
            $r = ApiClient::delete('/backstage/platform/tenants/' . rawurlencode($id) . '/domains/' . rawurlencode($did));
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        // ---------- Admins ----------
        case 'admins.list':
            need('id', $id);
            $r = ApiClient::get('/backstage/platform/tenants/' . rawurlencode($id) . '/admins');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'admins.grant':
            need('id', $id);
            $r = ApiClient::post('/backstage/platform/tenants/' . rawurlencode($id) . '/admins', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        case 'admins.revoke':
            need('id', $id);
            need('uid', $uid);
            $r = ApiClient::delete('/backstage/platform/tenants/' . rawurlencode($id) . '/admins/' . rawurlencode($uid));
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        // ---------- Modules (platform scope) ----------
        case 'modules.list':
            need('id', $id);
            $r = ApiClient::get('/backstage/platform/tenants/' . rawurlencode($id) . '/modules');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'modules.availability':
            need('id', $id);
            need('slug', $slug);
            $r = ApiClient::post('/backstage/platform/tenants/' . rawurlencode($id) . '/modules/' . rawurlencode($slug) . '/availability', $body);
            http_response_code((int) ($r['status'] ?? 500));
            echo json_encode($r['body'] ?? []);
            return;

        default:
            bail(400, 'unknown_op');
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
