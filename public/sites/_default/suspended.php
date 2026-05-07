<?php
declare(strict_types=1);

/**
 * Default-site suspended page.
 *
 * Served when tenants.status = suspended. Returns HTTP 503 so caches and
 * monitors classify the site as temporarily unavailable rather than gone.
 */

http_response_code(503);

$tenant = $GLOBALS['_default_site_tenant'] ?? null;
$locale = $GLOBALS['_default_site_locale'] ?? 'en_GB';

$pageTitle = $tenant instanceof \Daems\Domain\Tenant\Tenant
    ? $tenant->displayName($locale)
    : \Daems\Frontend\I18n::t('default.suspended.title');

if ($tenant instanceof \Daems\Domain\Tenant\Tenant) {
    require __DIR__ . '/partials/header.php';
} else {
    // No tenant context — emit a minimal standalone shell so the page is
    // still legible and conforms to the same visual language.
    ?><!DOCTYPE html>
    <html lang="<?= htmlspecialchars(substr($locale, 0, 2), ENT_QUOTES, 'UTF-8') ?>">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
      <link rel="stylesheet" href="/sites/_default/assets/default.css">
    </head>
    <body class="default-site">
    <?php
}
?>
<main class="default-suspended">
  <h2>
    <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.suspended.title'), ENT_QUOTES, 'UTF-8') ?>
  </h2>
  <p>
    <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.suspended.body'), ENT_QUOTES, 'UTF-8') ?>
  </p>
  <?php if ($tenant instanceof \Daems\Domain\Tenant\Tenant && $tenant->suspendedReason() !== null && $tenant->suspendedReason() !== ''): ?>
    <p class="reason">
      <em><?= htmlspecialchars((string) $tenant->suspendedReason(), ENT_QUOTES, 'UTF-8') ?></em>
    </p>
  <?php endif; ?>
</main>
<?php
if ($tenant instanceof \Daems\Domain\Tenant\Tenant) {
    require __DIR__ . '/partials/footer.php';
} else {
    ?>
    </body>
    </html>
    <?php
}
