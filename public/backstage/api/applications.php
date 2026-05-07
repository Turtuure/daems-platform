<?php

declare(strict_types=1);

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
    )
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$op = (string) ($_GET['op'] ?? '');

try {
    switch ($op) {
        case 'stats':
            $r = ApiClient::get('/backstage/applications/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'decided':
            $decisionRaw = $_GET['decision'] ?? 'rejected';
            $decision = in_array($decisionRaw, ['approved', 'rejected'], true) ? (string) $decisionRaw : 'rejected';
            $daysRaw = $_GET['days'] ?? 30;
            $days = is_numeric($daysRaw) ? max(1, min(365, (int) $daysRaw)) : 30;
            $limitRaw = $_GET['limit'] ?? 200;
            $limit = is_numeric($limitRaw) ? max(1, min(500, (int) $limitRaw)) : 200;
            $r = ApiClient::get('/backstage/applications/decided', [
                'decision' => $decision,
                'days'     => $days,
                'limit'    => $limit,
            ]);
            echo json_encode(['data' => $r ?? []]);
            return;

        case 'detail':
            $typeRaw = (string) ($_GET['type'] ?? '');
            $idRaw   = (string) ($_GET['id']   ?? '');
            if (!in_array($typeRaw, ['member', 'supporter'], true) || $idRaw === '') {
                http_response_code(422);
                echo json_encode(['error' => 'invalid_params']);
                return;
            }
            $r = ApiClient::get('/backstage/applications/' . rawurlencode($typeRaw) . '/' . rawurlencode($idRaw));
            echo json_encode(['data' => $r ?? []]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op', 'op' => $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
