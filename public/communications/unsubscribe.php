<?php

declare(strict_types=1);

/**
 * Public one-click unsubscribe handler (Milestone 0.8 / Wave H Item 1).
 *
 * Backs the HMAC-signed `?t=<token>` links embedded in newsletter and
 * marketing-category mail by SendNewsletter. The HMAC IS the authorisation
 * — no logged-in session needed. Token TTL is 30 days, controlled by
 * {@see \DaemsModule\Communications\Infrastructure\Auth\UnsubscribeTokenSigner}.
 *
 * Flow:
 *   1. GET /unsubscribe?t=...      → verify token, render branded confirm form
 *   2. POST /unsubscribe?t=...     → flip preference off, render success page
 *   3. invalid / expired token     → 410 Gone with friendly Finnish copy
 *
 * Branding (logo / primary colour / footer address) comes from the tenant's
 * TenantCommunicationSettings row resolved via the token payload's tenant id.
 *
 * Delegated from:
 *   - daem-society/public/index.php   → /unsubscribe → require this file
 *   - daems-platform/public/sites-router.php (default site) → same
 */

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Auth\UnsubscribeTokenSigner;

// Session may already be started by the delegating front-controller; only
// start a fresh one if needed.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Boot kernel if not already booted by an outer router.
if (!isset($kernel) || !$kernel instanceof \Daems\Infrastructure\Framework\Http\Kernel) {
    $kernel = $GLOBALS['_daems_kernel'] ?? null;
    if (!$kernel instanceof \Daems\Infrastructure\Framework\Http\Kernel) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $kernel = require __DIR__ . '/../../bootstrap/app.php';
    }
}
if (!$kernel instanceof \Daems\Infrastructure\Framework\Http\Kernel) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><h1>500 — Bootstrap error</h1></body></html>';
    exit;
}
$container = $kernel->container();

$token = (string) ($_GET['t'] ?? '');

/** @var UnsubscribeTokenSigner $signer */
$signer  = $container->make(UnsubscribeTokenSigner::class);
$payload = $token !== '' ? $signer->verify($token) : null;

if ($payload === null) {
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <title>Linkki vanhentunut</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 560px; margin: 60px auto; padding: 24px; color: #333; line-height: 1.5; }
        h1 { color: #c0392b; margin-bottom: 16px; }
        p { margin: 12px 0; }
    </style>
</head>
<body>
    <h1>Linkki ei kelpaa tai on vanhentunut</h1>
    <p>Tilauksen peruutuslinkki on yli 30 päivää vanha tai sitä on muokattu.</p>
    <p>Kirjaudu jäsenpalveluun muokataksesi viestintäasetuksiasi.</p>
</body>
</html>
    <?php
    exit;
}

$userId   = UserId::fromString($payload['u']);
$tenantId = TenantId::fromString($payload['t']);
$category = CommunicationCategory::from($payload['c']);

/** @var TenantCommunicationSettingsRepositoryInterface $settingsRepo */
$settingsRepo  = $container->make(TenantCommunicationSettingsRepositoryInterface::class);
$settings      = $settingsRepo->findForTenant($tenantId);
$primaryColor  = $settings?->brandPrimaryColor ?? '#2e5c8a';
$logoUrl       = $settings?->brandLogoUrl ?? '';
$footerAddress = $settings?->brandFooterAddress ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** @var UserCommunicationPreferenceRepositoryInterface $prefRepo */
    $prefRepo = $container->make(UserCommunicationPreferenceRepositoryInterface::class);
    try {
        // HMAC token IS the authorisation — bypass the UpdateUserCommunicationPreference
        // use case (which expects an ActingUser + would otherwise require a synthetic
        // self-edit guard for an unauthenticated visitor).
        $prefRepo->setFor($userId, $tenantId, $category, false);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fi"><head><meta charset="utf-8"><title>Virhe</title></head>'
           . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:24px;color:#333">'
           . '<h1>Virhe</h1><p>Yritä uudelleen myöhemmin.</p></body></html>';
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <title>Tilaus peruutettu</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 560px; margin: 60px auto; padding: 24px; color: #333; line-height: 1.5; }
        h1 { color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>; margin-bottom: 16px; }
        p { margin: 12px 0; }
        .footer { margin-top: 40px; font-size: 12px; color: #888; border-top: 1px solid #e0e0e0; padding-top: 14px; }
    </style>
</head>
<body>
    <?php if ($logoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="height:48px;display:block;margin-bottom:24px">
    <?php endif; ?>
    <h1>Olet poistettu listalta</h1>
    <p>Et saa enää viestejä kategoriasta <strong><?= htmlspecialchars($category->value, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
    <p>Voit aktivoida tilauksen uudelleen jäsenpalvelun viestintäasetuksissa.</p>
    <?php if ($footerAddress !== ''): ?>
        <div class="footer"><?= nl2br(htmlspecialchars($footerAddress, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php endif; ?>
</body>
</html>
    <?php
    exit;
}

// GET — show confirm form
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <title>Peruuta tilaus</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 560px; margin: 60px auto; padding: 24px; color: #333; line-height: 1.5; }
        h1 { color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>; margin-bottom: 16px; }
        p { margin: 12px 0; }
        button {
            background: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>;
            color: #fff;
            border: 0;
            padding: 12px 28px;
            font-size: 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 16px;
        }
        button:hover { opacity: 0.9; }
        .footer { margin-top: 40px; font-size: 12px; color: #888; border-top: 1px solid #e0e0e0; padding-top: 14px; }
    </style>
</head>
<body>
    <?php if ($logoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="height:48px;display:block;margin-bottom:24px">
    <?php endif; ?>
    <h1>Vahvista tilauksen peruutus</h1>
    <p>Klikkaa alta jos haluat lopettaa tilauksen <strong><?= htmlspecialchars($category->value, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
    <form method="post">
        <button type="submit">Vahvista peruutus</button>
    </form>
    <?php if ($footerAddress !== ''): ?>
        <div class="footer"><?= nl2br(htmlspecialchars($footerAddress, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php endif; ?>
</body>
</html>
