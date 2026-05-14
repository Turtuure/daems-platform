<?php
declare(strict_types=1);

/**
 * Backstage proxy for /api/backstage/communications/outbox[/{id}[/retry]].
 *
 * Forwards to the platform API:
 *   GET  /api/v1/backstage/communications/outbox          → list
 *   GET  /api/v1/backstage/communications/outbox/{id}     → show
 *   POST /api/v1/backstage/communications/outbox/{id}/retry → retry
 *
 * Uses raw cURL with `_proxy.php`'s helper for GETs (ApiClient::get has the
 * envelope-mismatch quirk catalogued in
 * ~/.claude/.../feedback_api_client_get_proxy_envelope.md). POSTs use
 * ApiClient::post which returns a {status,body} envelope cleanly.
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

// POST /api/backstage/communications/outbox/{id}/retry
if ($method === 'POST' && preg_match('#/communications/outbox/([0-9a-f-]+)/retry$#', $path, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/communications/outbox/' . $m[1] . '/retry', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// GET /api/backstage/communications/outbox/{id}
if ($method === 'GET' && preg_match('#/communications/outbox/([0-9a-f-]+)$#', $path, $m) === 1) {
    proxy_backend_get('/backstage/communications/outbox/' . $m[1]);
    exit;
}

// GET /api/backstage/communications/outbox (list, with optional query string)
if ($method === 'GET' && str_ends_with($path, '/communications/outbox')) {
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    proxy_backend_get('/backstage/communications/outbox' . $qs);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
