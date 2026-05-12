<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

if ($method === 'GET' && str_contains($uri, '/governance/board')) {
    $r = ApiClient::get('/backstage/governance/board');
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if ($method === 'POST' && str_contains($uri, '/bootstrap')) {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $r = ApiClient::post('/backstage/governance/board/bootstrap', $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
