<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/_proxy.php';

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
            $r = ApiClient::get('/backstage/members/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'search':
            // Typeahead picker for backstage forms (billing override, future
            // member-id inputs). Forwards `?q=<text>` to the backend list endpoint
            // and returns a slim {id, name, email, member_number, membership_type}
            // shape suitable for dropdown rendering.
            $q = (string) ($_GET['q'] ?? '');
            $qs = $q !== '' ? '?q=' . urlencode($q) . '&per_page=25' : '?per_page=25';
            proxy_backend_get('/backstage/members' . $qs);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op', 'op' => $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
