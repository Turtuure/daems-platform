<?php
/**
 * Wave H8 — Settings → Modules.
 *
 * Tenant-admin-facing page that lists every module the tenant's plan
 * grants them and lets the admin enable/disable them.
 *
 * Data source: /api/backstage/tenant-modules?op=list (proxy →
 * /api/v1/backstage/tenant/modules). Toggle action is op=state with
 * body {action:'enable'|'disable'}.
 */

declare(strict_types=1);

$pageTitle   = 'Modules';
$activePage  = 'settings';
$breadcrumbs = [
    ['label' => 'Settings', 'url' => '/backstage/settings'],
    ['label' => 'Modules'],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Modules</h1>
        <p class="page-header__subtitle">Activate the modules your tenant plan includes.</p>
    </div>
</div>

<div id="modules-status" class="tenants-status" aria-live="polite">Loading…</div>

<section class="settings-modules-section" aria-labelledby="mod-enabled-h">
    <h2 id="mod-enabled-h" class="settings-modules-section__title">In use</h2>
    <div class="settings-modules-grid" id="modules-enabled"></div>
</section>

<section class="settings-modules-section" aria-labelledby="mod-available-h">
    <h2 id="mod-available-h" class="settings-modules-section__title">Available — not activated</h2>
    <div class="settings-modules-grid" id="modules-available"></div>
</section>

<section class="settings-modules-section" id="modules-disabled-section" hidden aria-labelledby="mod-disabled-h">
    <h2 id="mod-disabled-h" class="settings-modules-section__title">Disabled</h2>
    <div class="settings-modules-grid" id="modules-disabled"></div>
</section>

<link rel="stylesheet" href="/backstage/pages/settings/modules/modules.css">
<script src="/backstage/pages/settings/modules/modules.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
