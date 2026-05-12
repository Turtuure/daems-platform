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

// Map proxy URI → backend URI
$map = [
    '/api/backstage/governance/decisions'                     => ['GET',  '/backstage/governance/decisions'],
    '/api/backstage/governance/decisions/approve-basic'       => ['POST', '/backstage/governance/decisions/approve-basic'],
    '/api/backstage/governance/decisions/invite-full'         => ['POST', '/backstage/governance/decisions/invite-full'],
    '/api/backstage/governance/decisions/award-subtier'       => ['POST', '/backstage/governance/decisions/award-subtier'],
    '/api/backstage/governance/decisions/revoke-subtier'      => ['POST', '/backstage/governance/decisions/revoke-subtier'],
    '/api/backstage/governance/decisions/subtier-crud'        => ['POST', '/backstage/governance/decisions/subtier-crud'],
    '/api/backstage/governance/decisions/remove-board-member' => ['POST', '/backstage/governance/decisions/remove-board-member'],
    '/api/backstage/governance/decisions/delegate-authority'  => ['POST', '/backstage/governance/decisions/delegate-authority'],
    '/api/backstage/governance/decisions/revoke-delegation'   => ['POST', '/backstage/governance/decisions/revoke-delegation'],
];

if (isset($map[(string) $uri])) {
    [$expectedMethod, $backendPath] = $map[(string) $uri];
    if ($method !== $expectedMethod) {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }
    $r = $method === 'GET'
        ? ApiClient::get($backendPath . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : ''))
        : ApiClient::post($backendPath, $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// Pattern routes (with id)
if (preg_match('#^/api/backstage/governance/decisions/([0-9a-fA-F-]{36})$#', (string) $uri, $m) && $method === 'GET') {
    $r = ApiClient::get('/backstage/governance/decisions/' . $m[1]);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if (preg_match('#^/api/backstage/governance/decisions/([0-9a-fA-F-]{36})/vote$#', (string) $uri, $m) && $method === 'POST') {
    $r = ApiClient::post('/backstage/governance/decisions/' . $m[1] . '/vote', $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if (preg_match('#^/api/backstage/governance/decisions/([0-9a-fA-F-]{36})/withdraw$#', (string) $uri, $m) && $method === 'POST') {
    $r = ApiClient::post('/backstage/governance/decisions/' . $m[1] . '/withdraw', $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found', 'uri' => $uri]);
