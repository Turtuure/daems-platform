<?php
/**
 * Wave H2 — Tenant edit shell.
 *
 * Single page that hosts five tabs (Basics, Domains, Admins, Modules,
 * Danger zone) selected via ?tab=. The router (public/backstage/router.php)
 * matches /backstage/platform/tenants/<id> via regex and forwards $tenantIdParam
 * to this template. Tab data is loaded by edit.js via the shared
 * /api/backstage/platform-tenants proxy.
 *
 * Skeleton scope: render the tab strip and a single content host node;
 * the per-tab templates are inline blocks below, hidden until JS picks one.
 *
 * @var string $tenantIdParam  Tenant id from the URL path (set by router.php).
 */

declare(strict_types=1);

$tenantIdRaw = isset($tenantIdParam) && is_string($tenantIdParam) ? $tenantIdParam : '';

if ($tenantIdRaw === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $tenantIdRaw) !== 1) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$tenantId   = $tenantIdRaw;
$activeTab  = (string) ($_GET['tab'] ?? 'basics');
$validTabs  = ['basics', 'domains', 'admins', 'modules', 'danger'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'basics';
}

$pageTitle   = 'Tenant';
$activePage  = 'platform-tenants';
$breadcrumbs = [
    ['label' => 'Platform'],
    ['label' => 'Tenants', 'url' => '/backstage/platform/tenants'],
    ['label' => 'Edit'],
];

$tabs = [
    'basics'  => 'Basics',
    'domains' => 'Domains',
    'admins'  => 'Admins',
    'modules' => 'Modules',
    'danger'  => 'Danger zone',
];

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title" id="tenant-edit-name">Tenant</h1>
        <p class="page-header__subtitle">
            <code id="tenant-edit-slug">—</code>
            <span class="tenants-pill" id="tenant-edit-status">—</span>
        </p>
    </div>
    <div>
        <a href="/backstage/platform/tenants" class="btn btn--ghost">&larr; Back to list</a>
    </div>
</div>

<nav class="tenant-tabs" role="tablist" aria-label="Tenant edit tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="?tab=<?= $esc($key) ?>"
           role="tab"
           class="tenant-tabs__tab<?= $activeTab === $key ? ' is-active' : '' ?>"
           aria-selected="<?= $activeTab === $key ? 'true' : 'false' ?>"
           data-tab="<?= $esc($key) ?>"><?= $esc($label) ?></a>
    <?php endforeach; ?>
</nav>

<div id="tenant-edit-status-line" class="tenants-status" aria-live="polite">Loading…</div>

<div class="tenant-edit-content" id="tenant-edit-content">
    <!-- Per-tab content is rendered by edit.js into this host. The five
         template blocks below are <template> nodes so they don't render
         until cloned in. -->
</div>

<!-- Per-tab templates — populated in subsequent H3-H7 tasks. -->
<template id="tab-tpl-basics"><div class="tenant-tab-placeholder">Basics tab coming in H3.</div></template>
<template id="tab-tpl-domains"><div class="tenant-tab-placeholder">Domains tab coming in H4.</div></template>
<template id="tab-tpl-admins"><div class="tenant-tab-placeholder">Admins tab coming in H5.</div></template>
<template id="tab-tpl-modules"><div class="tenant-tab-placeholder">Modules tab coming in H6.</div></template>
<template id="tab-tpl-danger"><div class="tenant-tab-placeholder">Danger zone coming in H7.</div></template>

<script>
window.DAEMS_TENANT_EDIT = {
    tenantId: <?= json_encode($tenantId, JSON_UNESCAPED_SLASHES) ?>,
    activeTab: <?= json_encode($activeTab, JSON_UNESCAPED_SLASHES) ?>
};
</script>

<link rel="stylesheet" href="/backstage/pages/platform/tenants/list.css">
<link rel="stylesheet" href="/backstage/pages/platform/tenants/edit.css">
<script src="/backstage/pages/platform/tenants/edit.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
