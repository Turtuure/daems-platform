<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string) ($_SERVER['REQUEST_URI'] ?? '');

if ($method === 'GET' && str_contains($uri, '/governance/billing/fee-schedules')) {
    // ApiClient::get returns the data-extracted payload (not an envelope), so
    // we can't reuse the envelope pattern that POST uses below. Issue cURL
    // directly so the upstream status code propagates verbatim.
    $qs = '';
    if (($q = parse_url($uri, PHP_URL_QUERY)) !== null && $q !== false) {
        $qs = '?' . $q;
    }
    $url = 'http://daems-platform.local/api/v1/backstage/governance/billing/fee-schedules' . $qs;
    $headers = ['Accept: application/json'];
    $token = $_SESSION['token'] ?? null;
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $json   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    http_response_code($status > 0 ? $status : 503);
    echo $json !== false ? (string) $json : json_encode(['error' => 'upstream_unreachable']);
    exit;
}

if ($method === 'POST' && str_contains($uri, '/governance/billing/fee-schedules')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $r = ApiClient::post('/backstage/governance/billing/fee-schedules', is_array($body) ? $body : []);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
