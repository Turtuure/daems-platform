<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u || empty($u['is_platform_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'gsa_required']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$r = ApiClient::post('/backstage/governance/gsa-overrides/approve-basic', $body);
http_response_code((int) ($r['status'] ?? 500));
echo json_encode($r['body'] ?? []);
