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

$op = (string) ($_GET['op'] ?? '');

try {
    switch ($op) {
        case 'stats':
            $r = ApiClient::get('/backstage/notifications/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op', 'op' => $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
