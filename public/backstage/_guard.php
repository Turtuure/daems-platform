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
 */

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
