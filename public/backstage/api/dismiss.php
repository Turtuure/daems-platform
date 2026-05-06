<?php

declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$role = $user['role'] ?? '';
if (empty($user['is_platform_admin']) && !in_array($role, ['global_system_administrator', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$type = $body['type'] ?? null;
$id   = $body['id'] ?? null;

if (!in_array($type, ['member', 'supporter', 'project_proposal', 'forum_report'], true) || !is_string($id) || $id === '') {
    http_response_code(422);
    echo json_encode(['error' => 'invalid_input']);
    exit;
}

$result = ApiClient::post('/backstage/applications/' . rawurlencode($type) . '/' . rawurlencode($id) . '/dismiss', []);

$status = (int) ($result['status'] ?? 500);
if ($status >= 200 && $status < 300) {
    http_response_code(204);
} else {
    http_response_code($status >= 400 && $status < 600 ? $status : 500);
    echo json_encode($result['body'] ?? ['error' => 'proxy_failed']);
}
