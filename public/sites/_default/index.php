<?php
declare(strict_types=1);

/**
 * Default-site home page.
 *
 * Tenant context is injected as $GLOBALS['_default_site_tenant'] /
 * $GLOBALS['_default_site_locale'] by public/sites-router.php, which also
 * loads the default-site lang map into Daems\Frontend\I18n.
 */

$tenant = $GLOBALS['_default_site_tenant'] ?? null;
$locale = $GLOBALS['_default_site_locale'] ?? 'en_GB';
if (!$tenant instanceof \Daems\Domain\Tenant\Tenant) {
    http_response_code(500);
    exit('Tenant context missing');
}

$pageTitle = $tenant->displayName($locale);
$welcome   = sprintf(
    \Daems\Frontend\I18n::t('default.home.welcome'),
    $tenant->displayName($locale),
);
$description = $tenant->publicDescription($locale) ?? '';

require __DIR__ . '/partials/header.php';
?>
<main class="default-home">
  <h2><?= htmlspecialchars($welcome, ENT_QUOTES, 'UTF-8') ?></h2>
  <?php if ($description !== ''): ?>
    <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
  <p class="cta-row">
    <a class="cta cta-primary" href="/join">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.home.cta_join'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <a class="cta cta-secondary" href="/login">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.home.cta_login'), ENT_QUOTES, 'UTF-8') ?>
    </a>
  </p>
</main>
<?php
require __DIR__ . '/partials/footer.php';
