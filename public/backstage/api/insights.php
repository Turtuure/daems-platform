<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (
    !$u
    || (
        empty($u['is_platform_admin'])
        && ($u['role'] ?? '') !== 'admin'
        && ($u['role'] ?? '') !== 'global_system_administrator'
    )
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$op    = (string) ($_GET['op'] ?? '');
$id    = (string) ($_GET['id'] ?? '');
$body  = json_decode((string) file_get_contents('php://input'), true) ?: [];

try {
    switch ($op) {
        case 'list':
            $qs = http_build_query(array_filter([
                'category' => $_GET['category'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $r = ApiClient::get('/backstage/insights' . ($qs !== '' ? "?{$qs}" : ''));
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'stats':
            $r = ApiClient::get('/backstage/insights/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'get':
            if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
            $r = ApiClient::get('/backstage/insights/' . rawurlencode($id));
            echo json_encode(['data' => $r]);
            return;

        case 'create':
            $r      = ApiClient::post('/backstage/insights', $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'update':
            if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
            $r      = ApiClient::post('/backstage/insights/' . rawurlencode($id), $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'delete':
            if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
            // Platform uses POST /{id}/delete (router lacks ->delete())
            $r      = ApiClient::post('/backstage/insights/' . rawurlencode($id) . '/delete', []);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op', 'op' => $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
