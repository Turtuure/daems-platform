<?php
declare(strict_types=1);

/**
 * Default-site join page (Wave I1 stub — populated in Wave I3).
 */

$tenant = $GLOBALS['_default_site_tenant'] ?? null;
$locale = $GLOBALS['_default_site_locale'] ?? 'en_GB';
if (!$tenant instanceof \Daems\Domain\Tenant\Tenant) {
    http_response_code(500);
    exit('Tenant context missing');
}

$pageTitle = $tenant->displayName($locale);

require __DIR__ . '/partials/header.php';
?>
<main class="default-join">
  <p>Join page — populated in Wave I3.</p>
</main>
<?php
require __DIR__ . '/partials/footer.php';
