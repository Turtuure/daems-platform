<?php
declare(strict_types=1);

use Daems\Frontend\I18n;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$token = (string) ($_SESSION['token'] ?? '');
$qs = http_build_query(array_filter([
    'q'     => $_GET['q']     ?? null,
    'type'  => $_GET['type']  ?? null,
    'limit' => $_GET['limit'] ?? null,
], static fn($v) => $v !== null && $v !== ''));

$ch = curl_init('http://daems-platform.local/api/v1/backstage/search' . ($qs !== '' ? "?{$qs}" : ''));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => array_filter([
        'Accept: application/json',
        'Accept-Language: ' . I18n::locale(),
        'Authorization: Bearer ' . $token,
        'Host: daems-platform.local',
    ]),
]);
$json = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($code >= 100 ? $code : 502);
echo (string) $json;
