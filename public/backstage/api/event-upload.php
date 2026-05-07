<?php

declare(strict_types=1);

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (
    !$u
    || (
        empty($u['is_platform_admin'])
        && ($u['role'] ?? '') !== 'admin'
        && ($u['role'] ?? '') !== 'global_system_administrator'
    )
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$eventId = (string) ($_GET['id'] ?? '');
if ($eventId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_id']);
    exit;
}

if (
    empty($_FILES['file'])
    || !is_uploaded_file((string) ($_FILES['file']['tmp_name'] ?? ''))
) {
    http_response_code(400);
    echo json_encode(['error' => 'no_file']);
    exit;
}

$base  = 'http://daems-platform.local/api/v1';
$token = (string) ($_SESSION['token'] ?? '');

$ch = curl_init($base . '/backstage/events/' . rawurlencode($eventId) . '/images');
$payload = [
    'file' => new \CURLFile(
        (string) $_FILES['file']['tmp_name'],
        (string) ($_FILES['file']['type'] ?? 'application/octet-stream'),
        (string) ($_FILES['file']['name'] ?? 'upload'),
    ),
];

$headers = ['Host: daems-platform.local'];
if ($token !== '') {
    $headers[] = 'Authorization: Bearer ' . $token;
}

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
]);

$json = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($code >= 100 ? $code : 502);
echo (string) $json;
