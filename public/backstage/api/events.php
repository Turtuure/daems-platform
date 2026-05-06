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
$body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
$base  = 'http://daems-platform.local/api/v1';
$token = (string) ($_SESSION['token'] ?? '');

/** Perform a GET against the backend and relay raw response. */
$rawGet = static function (string $path) use ($base, $token): void {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/json',
            $token !== '' ? ('Authorization: Bearer ' . $token) : null,
            'Host: daems-platform.local',
        ]),
    ]);
    $json = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    http_response_code($code >= 100 ? $code : 502);
    echo (string) $json;
};

try {
    switch ($op) {
        case 'stats':
            $r = ApiClient::get('/backstage/events/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'list':
            $qs = http_build_query(array_filter([
                'status' => $_GET['status'] ?? null,
                'type'   => $_GET['type'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/events' . ($qs !== '' ? "?{$qs}" : ''));
            return;

        case 'create':
            $r      = ApiClient::post('/backstage/events', $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'update':
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post('/backstage/events/' . rawurlencode($id), $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'publish':
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post('/backstage/events/' . rawurlencode($id) . '/publish', []);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'archive':
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post('/backstage/events/' . rawurlencode($id) . '/archive', []);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;

        case 'registrations':
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $rawGet('/backstage/events/' . rawurlencode($id) . '/registrations');
            return;

        case 'remove_registration':
            $id     = (string) ($_GET['id'] ?? '');
            $userId = (string) ($_GET['user_id'] ?? '');
            if ($id === '' || $userId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_param']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/events/' . rawurlencode($id) . '/registrations/' . rawurlencode($userId) . '/remove',
                []
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status >= 200 && $status < 300 ? 204 : $status);
            return;

        case 'delete_image':
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/events/' . rawurlencode($id) . '/images/delete',
                ['url' => (string) ($body['url'] ?? '')]
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status >= 200 && $status < 300 ? 204 : $status);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'bad_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_failed']);
}
