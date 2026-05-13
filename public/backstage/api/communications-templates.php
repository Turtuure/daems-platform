<?php
declare(strict_types=1);

/**
 * Backstage proxy for /api/backstage/communications/templates/{kind}/{locale}.
 *
 * Forwards to the platform API:
 *   GET → /api/v1/backstage/communications/templates/{kind}/{locale}
 *   PUT → /api/v1/backstage/communications/templates/{kind}/{locale}
 *
 * GETs use proxy_backend_get to dodge the ApiClient::get envelope quirk;
 * PUTs use ApiClient::put which returns a clean {status,body} envelope.
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

// /api/backstage/communications/templates/{kind}/{locale}
if (preg_match('#/communications/templates/([a-z_]+)/([a-zA-Z_]+)$#', $path, $m) === 1) {
    $kind   = $m[1];
    $locale = $m[2];
    $backend = '/backstage/communications/templates/' . rawurlencode($kind) . '/' . rawurlencode($locale);

    if ($method === 'GET') {
        proxy_backend_get($backend);
        exit;
    }
    if ($method === 'PUT') {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $r = ApiClient::put($backend, is_array($body) ? $body : []);
        http_response_code((int) ($r['status'] ?? 500));
        echo json_encode($r['body'] ?? []);
        exit;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'unknown_template_route', 'path' => $path]);
