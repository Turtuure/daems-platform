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

$post = static function (string $path, array $payload): void {
    $r      = ApiClient::post($path, $payload);
    $status = (int) ($r['status'] ?? 500);
    http_response_code($status);
    echo json_encode($r['body'] ?? []);
};

try {
    switch ($op) {
        case 'stats': {
            $rawGet('/backstage/forum/stats');
            return;
        }

        case 'reports_list': {
            $qs = http_build_query(array_filter([
                'status'      => $_GET['status'] ?? null,
                'target_type' => $_GET['target_type'] ?? null,
                'limit'       => $_GET['limit'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/forum/reports' . ($qs !== '' ? "?{$qs}" : ''));
            return;
        }

        case 'report_detail': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $rawGet('/backstage/forum/reports/' . rawurlencode($id));
            return;
        }

        case 'report_resolve': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/reports/' . rawurlencode($id) . '/resolve', [
                'action'      => (string) ($body['action'] ?? ''),
                'note'        => $body['note'] ?? null,
                'new_content' => $body['new_content'] ?? null,
            ]);
            return;
        }

        case 'report_dismiss': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/reports/' . rawurlencode($id) . '/dismiss', [
                'note' => $body['note'] ?? null,
            ]);
            return;
        }

        case 'topics_list': {
            $qs = http_build_query(array_filter([
                'category_id' => $_GET['category_id'] ?? null,
                'q'           => $_GET['q'] ?? null,
                'pinned_only' => $_GET['pinned_only'] ?? null,
                'locked_only' => $_GET['locked_only'] ?? null,
                'limit'       => $_GET['limit'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/forum/topics' . ($qs !== '' ? "?{$qs}" : ''));
            return;
        }

        case 'topic_pin':
        case 'topic_unpin':
        case 'topic_lock':
        case 'topic_unlock':
        case 'topic_delete': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $suffix = str_replace('topic_', '', $op);
            $post('/backstage/forum/topics/' . rawurlencode($id) . '/' . $suffix, []);
            return;
        }

        case 'posts_list': {
            $qs = http_build_query(array_filter([
                'topic_id' => $_GET['topic_id'] ?? null,
                'q'        => $_GET['q'] ?? null,
                'limit'    => $_GET['limit'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/forum/posts' . ($qs !== '' ? "?{$qs}" : ''));
            return;
        }

        case 'post_edit': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/posts/' . rawurlencode($id) . '/edit', [
                'new_content' => (string) ($body['new_content'] ?? ''),
                'note'        => $body['note'] ?? null,
            ]);
            return;
        }

        case 'post_delete': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/posts/' . rawurlencode($id) . '/delete', []);
            return;
        }

        case 'user_warn': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/users/' . rawurlencode($id) . '/warn', [
                'reason' => (string) ($body['reason'] ?? ''),
            ]);
            return;
        }

        case 'categories_list': {
            $rawGet('/backstage/forum/categories');
            return;
        }

        case 'category_create': {
            $post('/backstage/forum/categories', [
                'slug'        => (string) ($body['slug'] ?? ''),
                'name'        => (string) ($body['name'] ?? ''),
                'icon'        => (string) ($body['icon'] ?? ''),
                'description' => (string) ($body['description'] ?? ''),
                'sort_order'  => (int) ($body['sort_order'] ?? 0),
            ]);
            return;
        }

        case 'category_update': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/categories/' . rawurlencode($id), array_filter([
                'slug'        => $body['slug'] ?? null,
                'name'        => $body['name'] ?? null,
                'icon'        => $body['icon'] ?? null,
                'description' => $body['description'] ?? null,
                'sort_order'  => $body['sort_order'] ?? null,
            ], static fn($v) => $v !== null));
            return;
        }

        case 'category_delete': {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'missing_id']);
                return;
            }
            $post('/backstage/forum/categories/' . rawurlencode($id) . '/delete', []);
            return;
        }

        case 'audit_list': {
            $qs = http_build_query(array_filter([
                'action'    => $_GET['action'] ?? null,
                'performer' => $_GET['performer'] ?? null,
                'limit'     => $_GET['limit'] ?? null,
                'offset'    => $_GET['offset'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            $rawGet('/backstage/forum/audit' . ($qs !== '' ? "?{$qs}" : ''));
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
