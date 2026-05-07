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
<template id="tab-tpl-basics">
    <form class="tenant-form" id="tenant-basics-form" autocomplete="off">
        <div class="tenant-form__row">
            <label>Slug</label>
            <input type="text" name="slug" id="tb-slug" readonly>
            <small>Slug is immutable.</small>
        </div>

        <div class="tenant-form__row">
            <label>Display name (per locale)</label>
            <div class="tenant-locale-cards" id="tb-display-name-cards"></div>
        </div>

        <div class="tenant-form__row">
            <label>Public description (per locale)</label>
            <div class="tenant-locale-cards" id="tb-public-description-cards"></div>
        </div>

        <div class="tenant-form__row">
            <label>Supported locales</label>
            <div class="tenant-form__locales" id="tb-supported-locales">
                <label><input type="checkbox" name="supportedLocales" value="fi_FI"> fi_FI</label>
                <label><input type="checkbox" name="supportedLocales" value="en_GB"> en_GB</label>
                <label><input type="checkbox" name="supportedLocales" value="sw_TZ"> sw_TZ</label>
            </div>
        </div>

        <div class="tenant-form__row">
            <label for="tb-default-locale">Default locale</label>
            <select name="defaultLocale" id="tb-default-locale">
                <option value="fi_FI">fi_FI</option>
                <option value="en_GB">en_GB</option>
                <option value="sw_TZ">sw_TZ</option>
            </select>
        </div>

        <div class="tenant-form__row">
            <label for="tb-prefix">Member-number prefix</label>
            <input type="text" name="memberNumberPrefix" id="tb-prefix" pattern="[A-Z0-9\-]*">
        </div>

        <div class="tenant-form__actions">
            <span class="tenant-form__status" id="tb-status" aria-live="polite"></span>
            <button type="submit" class="btn btn--primary" id="tb-save">Save</button>
        </div>
    </form>
</template>
<template id="tab-tpl-domains">
    <div class="tenant-tab-toolbar">
        <span class="tenants-status" id="td-status">Loading…</span>
        <button type="button" class="btn btn--primary" id="td-add-btn">+ Add domain</button>
    </div>
    <table class="tenant-tab-table" id="td-table" hidden>
        <thead>
            <tr>
                <th>Hostname</th>
                <th>Primary</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="td-tbody"></tbody>
    </table>

    <div class="tenants-modal" id="td-add-modal" hidden role="dialog" aria-modal="true">
        <div class="tenants-modal__backdrop" data-close></div>
        <div class="tenants-modal__panel">
            <header class="tenants-modal__header">
                <h2 class="tenants-modal__title">Add domain</h2>
                <button type="button" class="tenants-modal__close" data-close aria-label="Close">×</button>
            </header>
            <form id="td-add-form" class="tenants-modal__form">
                <label class="tenants-field">
                    <span class="tenants-field__label">Hostname *</span>
                    <input type="text" name="hostname" required placeholder="example.org">
                </label>
                <label class="tenants-field">
                    <span class="tenants-field__label">
                        <input type="checkbox" name="isPrimary"> Mark as primary
                    </span>
                </label>
                <div class="tenants-modal__actions">
                    <button type="button" class="btn btn--ghost" data-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Add</button>
                </div>
                <div id="td-add-error" class="tenants-modal__error" aria-live="polite"></div>
            </form>
        </div>
    </div>
</template>
<template id="tab-tpl-admins">
    <div class="tenant-tab-toolbar">
        <div>
            <span class="tenants-status">Total tenant admins: <strong id="ta-count">—</strong></span>
        </div>
        <button type="button" class="btn btn--primary" id="ta-add-btn">+ Grant admin</button>
    </div>

    <div class="tenant-tab-placeholder" id="ta-list-placeholder">
        Full list pending — Wave G follow-up.
        <br><small>The platform's findAdminsForTenant repo method is not yet
        implemented; only the count is available. Use the grant/revoke
        controls above (revoke needs a known userId until the list is wired).</small>
    </div>

    <!-- Manual revoke fallback while the list is unavailable -->
    <form class="tenant-form" id="ta-revoke-form" style="margin-top:1rem;">
        <div class="tenant-form__row">
            <label for="ta-revoke-uid">Revoke admin by user id</label>
            <input type="text" name="userId" id="ta-revoke-uid" placeholder="user id (UUID)">
        </div>
        <div class="tenant-form__actions">
            <button type="submit" class="btn btn--ghost">Revoke</button>
        </div>
    </form>

    <div class="tenants-modal" id="ta-add-modal" hidden role="dialog" aria-modal="true">
        <div class="tenants-modal__backdrop" data-close></div>
        <div class="tenants-modal__panel">
            <header class="tenants-modal__header">
                <h2 class="tenants-modal__title">Grant tenant-admin role</h2>
                <button type="button" class="tenants-modal__close" data-close aria-label="Close">×</button>
            </header>
            <form id="ta-add-form" class="tenants-modal__form">
                <label class="tenants-field">
                    <span class="tenants-field__label">User id *</span>
                    <input type="text" name="userId" required placeholder="user id (UUID)">
                    <small class="tenants-field__hint">User-search component to come; for now paste the id.</small>
                </label>
                <div class="tenants-modal__actions">
                    <button type="button" class="btn btn--ghost" data-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Grant</button>
                </div>
                <div id="ta-add-error" class="tenants-modal__error" aria-live="polite"></div>
            </form>
        </div>
    </div>
</template>
<template id="tab-tpl-modules">
    <div class="tenant-tab-toolbar">
        <span class="tenants-status" id="tm-status">Loading…</span>
    </div>
    <table class="tenant-tab-table" id="tm-table" hidden>
        <thead>
            <tr>
                <th>Slug</th>
                <th>Name</th>
                <th>Category</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="tm-tbody"></tbody>
    </table>

    <!-- Cascade-revoke confirm dialog. Reused for plain reasons too. -->
    <div class="tenant-confirm" id="tm-confirm" hidden role="dialog" aria-modal="true">
        <div class="tenant-confirm__backdrop" data-close></div>
        <div class="tenant-confirm__panel">
            <h3 class="tenant-confirm__title" id="tm-confirm-title">Revoke module</h3>
            <p class="tenant-confirm__body" id="tm-confirm-body">
                Revoking will force-disable any modules that depend on this one. Provide a reason for the audit log.
            </p>
            <div class="tenant-confirm__field">
                <label for="tm-reason">Reason *</label>
                <textarea id="tm-reason" rows="3" required></textarea>
            </div>
            <div class="tenant-confirm__actions">
                <button type="button" class="btn btn--ghost" data-close>Cancel</button>
                <button type="button" class="btn btn--primary" id="tm-confirm-go">Revoke</button>
            </div>
        </div>
    </div>
</template>
<template id="tab-tpl-danger">
    <!-- Suspend card (only shown when active) -->
    <div class="tenant-danger-card tenant-danger-card--suspend" id="td-suspend-card" hidden>
        <h3>Suspend tenant</h3>
        <p>
            Suspending blocks tenant logins, hides the public site, and
            preserves all data. The tenant can be reactivated later.
        </p>
        <form id="td-suspend-form" class="tenant-form">
            <div class="tenant-form__row">
                <label for="td-suspend-reason">Reason *</label>
                <textarea id="td-suspend-reason" name="reason" rows="3" required
                    placeholder="Why is this tenant being suspended?"></textarea>
            </div>
            <div class="tenant-form__actions">
                <button type="submit" class="btn btn--primary">Suspend</button>
            </div>
        </form>
    </div>

    <!-- Reactivate card (only shown when suspended) -->
    <div class="tenant-danger-card tenant-danger-card--reactivate" id="td-reactivate-card" hidden>
        <h3>Reactivate tenant</h3>
        <p id="td-reactivate-reason-line">This tenant is currently suspended.</p>
        <div class="tenant-form__actions">
            <button type="button" class="btn btn--primary" id="td-reactivate-btn">Reactivate</button>
        </div>
    </div>

    <!-- Hard-delete (out of scope) -->
    <div class="tenant-danger-card tenant-danger-card--info">
        <h3>Hard-delete tenant</h3>
        <p>Future feature: GDPR-compliant tenant deletion (out of scope).</p>
    </div>
</template>

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
