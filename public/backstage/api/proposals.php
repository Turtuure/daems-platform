<?php

declare(strict_types=1);

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
        case 'list':
            $rawGet('/backstage/proposals');
            return;

        case 'approve': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/proposals/' . rawurlencode($id) . '/approve',
                ['note' => (string) ($body['note'] ?? '')]
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;
        }

        case 'reject': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/proposals/' . rawurlencode($id) . '/reject',
                ['note' => (string) ($body['note'] ?? '')]
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status >= 200 && $status < 300 ? 204 : $status);
            return;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'bad_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_failed']);
}
