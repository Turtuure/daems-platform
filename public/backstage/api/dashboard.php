<?php

declare(strict_types=1);

/**
 * Backstage proxy for dashboard layout + catalog endpoints.
 *
 * Routes (via api-router.php map):
 *   GET    /api/backstage/dashboard/layout   → platform GET  /backstage/dashboard/layout
 *   PUT    /api/backstage/dashboard/layout   → platform PUT  /backstage/dashboard/layout
 *   DELETE /api/backstage/dashboard/layout   → platform DELETE …
 *   GET    /api/backstage/dashboard/catalog  → platform GET  /backstage/dashboard/catalog
 *
 * The platform endpoint enforces auth via Bearer token forwarded by ApiClient.
 * This proxy only short-circuits non-backstage roles to 401 to avoid a
 * round-trip when we already know the session isn't allowed.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (
    !$u
    || (
        empty($u['is_platform_admin'])
        && ($u['role'] ?? '') !== 'admin'
        && ($u['role'] ?? '') !== 'global_system_administrator'
        && ($u['role'] ?? '') !== 'moderator'
    )
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

try {
    if ($uri === '/api/backstage/dashboard/layout') {
        if ($method === 'GET') {
            $r = ApiClient::get('/backstage/dashboard/layout');
            echo json_encode(['data' => $r ?? []]);
            return;
        }
        if ($method === 'PUT') {
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = [];
            }
            $resp = ApiClient::put('/backstage/dashboard/layout', $body);
            http_response_code($resp['status']);
            echo $resp['status'] === 204 ? '' : json_encode($resp['body']);
            return;
        }
        if ($method === 'DELETE') {
            $resp = ApiClient::delete('/backstage/dashboard/layout');
            http_response_code($resp['status']);
            echo $resp['status'] === 204 ? '' : json_encode($resp['body']);
            return;
        }
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        return;
    }

    if ($uri === '/api/backstage/dashboard/catalog' && $method === 'GET') {
        $r = ApiClient::get('/backstage/dashboard/catalog');
        echo json_encode(['data' => $r ?? []]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'uri' => $uri]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
