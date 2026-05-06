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
            $r = ApiClient::get('/backstage/projects/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'list': {
            $qs = http_build_query(array_filter([
                'status'   => $_GET['status'] ?? null,
                'category' => $_GET['category'] ?? null,
                'featured' => isset($_GET['featured']) && $_GET['featured'] === '1' ? '1' : null,
                'q'        => $_GET['q'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/projects' . ($qs !== '' ? "?{$qs}" : ''));
            return;
        }

        case 'create': {
            $r      = ApiClient::post('/backstage/projects', $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;
        }

        case 'update': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post('/backstage/projects/' . rawurlencode($id), $body);
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;
        }

        case 'status': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/projects/' . rawurlencode($id) . '/status',
                ['status' => (string) ($body['status'] ?? '')]
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;
        }

        case 'featured': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/projects/' . rawurlencode($id) . '/featured',
                ['featured' => (bool) ($body['featured'] ?? false)]
            );
            $status = (int) ($r['status'] ?? 500);
            http_response_code($status);
            echo json_encode($r['body'] ?? []);
            return;
        }

        case 'comments_recent': {
            $qs = isset($_GET['limit']) ? '?limit=' . (int) $_GET['limit'] : '';
            $rawGet('/backstage/comments/recent' . $qs);
            return;
        }

        case 'delete_comment': {
            $id        = (string) ($_GET['id'] ?? '');
            $commentId = (string) ($_GET['comment_id'] ?? '');
            if ($id === '' || $commentId === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_param']);
                return;
            }
            $r      = ApiClient::post(
                '/backstage/projects/' . rawurlencode($id) . '/comments/' . rawurlencode($commentId) . '/delete',
                ['reason' => (string) ($body['reason'] ?? '')]
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
