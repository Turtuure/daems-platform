<?php
declare(strict_types=1);

/**
 * Backstage proxy for /api/backstage/communications/newsletters[/{id}[/send]].
 *
 * Forwards to the platform API:
 *   GET    /api/v1/backstage/communications/newsletters             → list
 *   POST   /api/v1/backstage/communications/newsletters             → create
 *   PATCH  /api/v1/backstage/communications/newsletters/{id}        → update
 *   DELETE /api/v1/backstage/communications/newsletters/{id}        → destroy
 *   POST   /api/v1/backstage/communications/newsletters/{id}/send   → send
 *
 * GETs use proxy_backend_get to dodge the ApiClient::get envelope quirk;
 * POST/PATCH/DELETE use ApiClient which returns a clean {status,body} envelope.
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

// POST /api/backstage/communications/newsletters/{id}/send
if ($method === 'POST' && preg_match('#/communications/newsletters/([0-9a-f-]+)/send$#', $path, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/communications/newsletters/' . $m[1] . '/send', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// PATCH /api/backstage/communications/newsletters/{id}
if ($method === 'PATCH' && preg_match('#/communications/newsletters/([0-9a-f-]+)$#', $path, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::patch('/backstage/communications/newsletters/' . $m[1], is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// DELETE /api/backstage/communications/newsletters/{id}
if ($method === 'DELETE' && preg_match('#/communications/newsletters/([0-9a-f-]+)$#', $path, $m) === 1) {
    $r = ApiClient::delete('/backstage/communications/newsletters/' . $m[1]);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// POST /api/backstage/communications/newsletters
if ($method === 'POST' && str_ends_with($path, '/communications/newsletters')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/communications/newsletters', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// GET /api/backstage/communications/newsletters
if ($method === 'GET' && str_ends_with($path, '/communications/newsletters')) {
    proxy_backend_get('/backstage/communications/newsletters');
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
