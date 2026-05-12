<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
if (str_ends_with((string) $uri, '.php')) {
    $uri = substr((string) $uri, 0, -4);
}
$body = $method === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
$qs   = (string) ($_SERVER['QUERY_STRING'] ?? '');

if ($uri === '/api/backstage/governance/expulsions' && $method === 'GET') {
    $r = ApiClient::get('/backstage/governance/expulsions' . ($qs !== '' ? '?' . $qs : ''));
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if ($uri === '/api/backstage/governance/expulsions' && $method === 'POST') {
    $r = ApiClient::post('/backstage/governance/expulsions', $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if (preg_match('#^/api/backstage/governance/expulsions/([0-9a-fA-F-]{36})$#', (string) $uri, $m) && $method === 'GET') {
    $r = ApiClient::get('/backstage/governance/expulsions/' . $m[1]);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
foreach (['statement', 'advance-to-vote', 'appeal'] as $action) {
    if (preg_match('#^/api/backstage/governance/expulsions/([0-9a-fA-F-]{36})/' . $action . '$#', (string) $uri, $m) && $method === 'POST') {
        $r = ApiClient::post('/backstage/governance/expulsions/' . $m[1] . '/' . $action, $body);
        http_response_code((int) ($r['status'] ?? 500));
        echo json_encode($r['body'] ?? []);
        exit;
    }
}
http_response_code(404);
echo json_encode(['error' => 'not_found', 'uri' => $uri]);
