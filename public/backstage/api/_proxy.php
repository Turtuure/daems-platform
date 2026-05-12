<?php
declare(strict_types=1);

/**
 * Proxy a GET to the platform backend with status passthrough.
 *
 * Why this exists: ApiClient::get() returns the data-extracted payload (mixed),
 * NOT a {status, body} envelope like its siblings. Naïvely treating it as an
 * envelope sends HTTP 500 on every successful GET. This helper bypasses
 * ApiClient::get and forwards the upstream response verbatim.
 *
 * The caller is responsible for any query-string suffix appended to $backendPath.
 *
 * @param string $backendPath e.g. '/backstage/governance/board' or
 *                            '/backstage/governance/expulsions?status=open'
 */
function proxy_backend_get(string $backendPath): void
{
    $url = 'http://daems-platform.local/api/v1' . $backendPath;
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
}
