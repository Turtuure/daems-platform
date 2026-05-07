<?php
declare(strict_types=1);

/**
 * Default-site suspended page (Wave I1 stub — populated in Wave I2).
 *
 * Served when tenants.status = suspended. Returns HTTP 503 so caches and
 * monitors classify the site as temporarily unavailable rather than gone.
 */

http_response_code(503);

$tenant = $GLOBALS['_default_site_tenant'] ?? null;
$locale = $GLOBALS['_default_site_locale'] ?? 'en_GB';

$pageTitle = $tenant instanceof \Daems\Domain\Tenant\Tenant
    ? $tenant->displayName($locale)
    : 'Site temporarily unavailable';

if ($tenant instanceof \Daems\Domain\Tenant\Tenant) {
    require __DIR__ . '/partials/header.php';
}
?>
<main class="default-suspended">
  <p>Site temporarily unavailable — populated in Wave I2.</p>
</main>
<?php
if ($tenant instanceof \Daems\Domain\Tenant\Tenant) {
    require __DIR__ . '/partials/footer.php';
}
