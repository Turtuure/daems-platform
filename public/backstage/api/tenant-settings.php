<?php

declare(strict_types=1);

use Daems\Frontend\ApiClient;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

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

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$r      = ApiClient::post('/backstage/tenant/settings', $body);
$status = (int) ($r['status'] ?? 500);
http_response_code($status);

// Keep the local session in sync on a successful update so the prefix
// appears correctly on /profile without requiring a re-login.
if ($status >= 200 && $status < 300) {
    $newPrefix = $body['member_number_prefix'] ?? null;
    $_SESSION['tenant'] = is_array($_SESSION['tenant'] ?? null) ? $_SESSION['tenant'] : [];
    $_SESSION['tenant']['member_number_prefix'] = is_string($newPrefix) && $newPrefix !== '' ? $newPrefix : null;
}

echo json_encode($r['body'] ?? []);
