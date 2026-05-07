<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;
use Daems\Frontend\I18n;

$email    = (string) ($_POST['email']    ?? '');
$password = (string) ($_POST['password'] ?? '');
$redirect = (string) ($_POST['redirect'] ?? '/backstage');

$result = ApiClient::post('/auth/login', ['email' => $email, 'password' => $password]);
$payload = is_array($result) ? ($result['body']['data'] ?? null) : null;
$user = null;
if (is_array($payload)) {
    if (isset($payload['user']) && is_array($payload['user'])) {
        $user = $payload['user'];
    } elseif (isset($payload['id'])) {
        $user = $payload;
    }
}

if (!is_array($result) || $result['status'] !== 200 || !is_array($user)) {
    $errorKey = $result['status'] >= 500 ? 'backstage.login.error.internal' : 'backstage.login.error.invalid';
    header('Location: /backstage/login?error=' . rawurlencode(I18n::t($errorKey)) . '&redirect=' . rawurlencode($redirect));
    exit;
}

$token = is_array($payload) ? ($payload['token'] ?? null) : null;

// Enrich session with /auth/me — synthesize legacy 'role' field for layout guard.
$tenantRole = null;
$tenantData = null;
$meUser     = null;
if (is_string($token)) {
    $_SESSION['token'] = $token;
    $me = ApiClient::get('/auth/me');
    if (is_array($me)) {
        if (is_string($me['role_in_tenant'] ?? null)) $tenantRole = $me['role_in_tenant'];
        if (is_array($me['tenant'] ?? null))           $tenantData = $me['tenant'];
        if (is_array($me['user']   ?? null))           $meUser     = $me['user'];
    }
}

$user = is_array($meUser) ? $meUser : $user;
$user['is_platform_admin'] = $user['is_platform_admin'] ?? false;
$user['role'] = $user['is_platform_admin']
    ? 'global_system_administrator'
    : ($tenantRole ?? 'registered');
if ($tenantData !== null) {
    $user['tenant'] = $tenantData;
}

$_SESSION['user'] = $user;

// Only allow internal redirect targets under /backstage
$path = parse_url($redirect, PHP_URL_PATH);
$safe = is_string($path) && str_starts_with($path, '/backstage') ? $redirect : '/backstage';
header('Location: ' . $safe);
exit;
