<?php
declare(strict_types=1);

require_once __DIR__ . '/_proxy.php';

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}
proxy_backend_get('/backstage/governance/eligibility/full-membership');
