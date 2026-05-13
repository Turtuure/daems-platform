<?php
declare(strict_types=1);

/**
 * Backstage proxy for /api/backstage/communications/settings[/smtp-test].
 *
 * Forwards to the platform API:
 *   GET  /api/v1/backstage/communications/settings           → show
 *   PUT  /api/v1/backstage/communications/settings           → update
 *   POST /api/v1/backstage/communications/settings/smtp-test → test
 *
 * Uses `_proxy.php`'s `proxy_backend_get` for the read path (works around
 * the ApiClient::get envelope quirk catalogued in
 * ~/.claude/.../feedback_api_client_get_proxy_envelope.md) and `ApiClient::put`
 * / `ApiClient::post` for the writes (those return {status,body} cleanly).
 */

require_once __DIR__ . '/_proxy.php';

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$u = $_SESSION['user'] ?? null;
if (!$u) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string) ($_SERVER['REQUEST_URI'] ?? '');
$path   = (string) (parse_url($uri, PHP_URL_PATH) ?? '');

// POST /api/backstage/communications/settings/smtp-test
if ($method === 'POST' && str_ends_with($path, '/communications/settings/smtp-test')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/communications/settings/smtp-test', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// PUT /api/backstage/communications/settings
if ($method === 'PUT' && str_ends_with($path, '/communications/settings')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::put('/backstage/communications/settings', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// GET /api/backstage/communications/settings
if ($method === 'GET' && str_ends_with($path, '/communications/settings')) {
    proxy_backend_get('/backstage/communications/settings');
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
