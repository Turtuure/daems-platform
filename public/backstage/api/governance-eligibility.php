<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}
$r = ApiClient::get('/backstage/governance/eligibility/full-membership');
http_response_code((int) ($r['status'] ?? 500));
echo json_encode($r['body'] ?? []);
