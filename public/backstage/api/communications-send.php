<?php
declare(strict_types=1);

/**
 * Backstage proxy for /api/backstage/communications/send.
 *
 * Forwards POST → /api/v1/backstage/communications/send on the platform.
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$r = ApiClient::post('/backstage/communications/send', is_array($body) ? $body : []);
http_response_code((int) ($r['status'] ?? 500));
echo json_encode($r['body'] ?? []);
