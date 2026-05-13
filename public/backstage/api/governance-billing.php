<?php
declare(strict_types=1);

require_once __DIR__ . '/_proxy.php';

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string) ($_SERVER['REQUEST_URI'] ?? '');

if ($method === 'GET' && str_contains($uri, '/governance/billing/fee-schedules')) {
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    proxy_backend_get('/backstage/governance/billing/fee-schedules' . $qs);
    exit;
}

if ($method === 'POST' && str_contains($uri, '/governance/billing/fee-schedules')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/governance/billing/fee-schedules', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// CSV import (Wave G — Nordea bank statement)
if ($method === 'POST' && str_contains($uri, '/governance/billing/payments/import-csv/confirm')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/governance/billing/payments/import-csv/confirm', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

if ($method === 'POST' && str_contains($uri, '/governance/billing/payments/import-csv')) {
    // Multipart upload: forward $_FILES['csv'] via cURL to the platform API.
    if (!isset($_FILES['csv']['tmp_name']) || !is_string($_FILES['csv']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'csv file required']);
        exit;
    }
    $cookieHeader = '';
    foreach ($_COOKIE as $k => $v) {
        $cookieHeader .= urlencode($k) . '=' . urlencode((string) $v) . '; ';
    }
    $tenantHost = (string) ($_SERVER['HTTP_HOST'] ?? 'daems-platform.local');
    $ch = curl_init('http://daems-platform.local/api/v1/backstage/governance/billing/payments/import-csv');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'csv' => new CURLFile($_FILES['csv']['tmp_name'], 'text/csv', (string) ($_FILES['csv']['name'] ?? 'upload.csv')),
        ],
        CURLOPT_HTTPHEADER     => [
            'Cookie: ' . rtrim($cookieHeader, '; '),
            'X-Daems-Forwarded-Host: ' . $tenantHost,
        ],
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    http_response_code($status);
    echo $body;
    exit;
}

// Per-user fee overrides (Wave D)
if ($method === 'POST' && preg_match('#/governance/billing/overrides/([0-9a-f-]+)/revoke#', $uri, $m) === 1) {
    $r = ApiClient::post('/backstage/governance/billing/overrides/' . $m[1] . '/revoke', []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

if ($method === 'GET' && str_contains($uri, '/governance/billing/overrides')) {
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    proxy_backend_get('/backstage/governance/billing/overrides' . $qs);
    exit;
}

if ($method === 'POST' && str_contains($uri, '/governance/billing/overrides')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/governance/billing/overrides', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

// Invoice list + row actions + audit (Wave E)
if ($method === 'POST' && preg_match('#/governance/billing/invoices/([0-9a-f-]+)/(mark-paid|waive|reduce)#', $uri, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/governance/billing/invoices/' . $m[1] . '/' . $m[2], is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

if ($method === 'GET' && preg_match('#/governance/billing/invoices/([0-9a-f-]+)/audit#', $uri, $m) === 1) {
    proxy_backend_get('/backstage/governance/billing/invoices/' . $m[1] . '/audit');
    exit;
}

if ($method === 'GET' && str_contains($uri, '/governance/billing/kpi')) {
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    proxy_backend_get('/backstage/governance/billing/kpi' . $qs);
    exit;
}

if ($method === 'GET' && str_contains($uri, '/governance/billing/invoices')) {
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    proxy_backend_get('/backstage/governance/billing/invoices' . $qs);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
