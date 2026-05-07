<?php
declare(strict_types=1);

/**
 * Backstage role guard — only platform admins (is_platform_admin=true) and
 * tenant admins (user_tenants.role='admin' on the active tenant) reach the UI.
 *
 * Session shape (set by /backstage/login handler):
 *   $_SESSION['user']                       = associative user array from /auth/me
 *   $_SESSION['token']                      = bearer token
 *   $_SESSION['user']['role']               = legacy alias ('admin' or 'global_system_administrator')
 *   $_SESSION['user']['is_platform_admin']  = bool
 *
 * Spec AC-11 — suspended tenants must be refused with a 503 BEFORE the
 * auth-redirect, so that even authenticated admins of a suspended tenant
 * see the suspension page (and not a login form they cannot satisfy).
 * `_module-guard.php` runs ahead of this file in router.php and stashes
 * the resolved Tenant entity in $GLOBALS['daems_backstage_tenant'].
 */

if (
    isset($GLOBALS['daems_backstage_tenant'])
    && $GLOBALS['daems_backstage_tenant'] !== null
    && $GLOBALS['daems_backstage_tenant']->suspended()
) {
    http_response_code(503);
    $__suspendedReason = $GLOBALS['daems_backstage_tenant']->suspendedReason() ?? 'Site temporarily unavailable';
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Suspended</title></head><body>';
    echo '<h1>Tenant suspended</h1><p>' . htmlspecialchars($__suspendedReason, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

$__user = $_SESSION['user'] ?? null;
$__role = is_array($__user) ? ($__user['role'] ?? '') : '';
$__isPlatformAdmin = is_array($__user) ? ($__user['is_platform_admin'] ?? false) : false;

$__isAdmin = $__isPlatformAdmin === true
          || $__role === 'admin'
          || $__role === 'global_system_administrator';

if (!$__isAdmin) {
    header('Location: /backstage/login?redirect=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/backstage')));
    exit;
}
