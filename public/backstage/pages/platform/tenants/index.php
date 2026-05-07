<?php
/**
 * Wave H1 — Platform Tenants list page.
 *
 * Skeleton UI for GSAs to view + create tenants. Polish (filters,
 * sortable columns, density, etc.) is deferred — the structure here is
 * intentionally minimal so the user can iterate in the browser.
 *
 * Data source: /api/backstage/platform-tenants?op=list (proxy →
 * /api/v1/backstage/platform/tenants).
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'Tenants';
$activePage  = 'platform-tenants';
$breadcrumbs = [
    ['label' => 'Platform'],
    ['label' => 'Tenants'],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Tenants</h1>
        <p class="page-header__subtitle">Manage every tenant on the platform — domains, admins, modules, lifecycle.</p>
    </div>
    <div>
        <button type="button" class="btn btn--primary" id="tenants-new-btn">+ New tenant</button>
    </div>
</div>

<div class="tenants-toolbar">
    <input type="search" id="tenants-search" class="tenants-toolbar__search" placeholder="Search by slug or name…" />
    <select id="tenants-status-filter" class="tenants-toolbar__filter">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
    </select>
</div>

<div class="card">
    <div class="card__body">
        <div id="tenants-status" class="tenants-status" aria-live="polite">Loading…</div>
        <table class="tenants-table" id="tenants-table" hidden>
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Display name</th>
                    <th>Status</th>
                    <th>Domains</th>
                    <th>Members</th>
                    <th>Admins</th>
                    <th>Modules</th>
                </tr>
            </thead>
            <tbody id="tenants-tbody"></tbody>
        </table>
    </div>
</div>

<!-- New-tenant modal -->
<div class="tenants-modal" id="tenants-new-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tenants-new-title">
    <div class="tenants-modal__backdrop" data-close></div>
    <div class="tenants-modal__panel">
        <header class="tenants-modal__header">
            <h2 id="tenants-new-title" class="tenants-modal__title">Create tenant</h2>
            <button type="button" class="tenants-modal__close" data-close aria-label="Close">×</button>
        </header>
        <form id="tenants-new-form" class="tenants-modal__form">
            <label class="tenants-field">
                <span class="tenants-field__label">Slug *</span>
                <input type="text" name="slug" required pattern="[a-z][a-z0-9-]*" placeholder="my-society">
                <small class="tenants-field__hint">Lowercase, alphanumeric + dashes; cannot be changed.</small>
            </label>

            <label class="tenants-field">
                <span class="tenants-field__label">Display name (en_GB) *</span>
                <input type="text" name="display_name_en" required placeholder="My Society">
            </label>

            <fieldset class="tenants-field">
                <legend class="tenants-field__label">Supported locales *</legend>
                <label><input type="checkbox" name="locales[]" value="fi_FI" checked> fi_FI</label>
                <label><input type="checkbox" name="locales[]" value="en_GB" checked> en_GB</label>
                <label><input type="checkbox" name="locales[]" value="sw_TZ"> sw_TZ</label>
            </fieldset>

            <label class="tenants-field">
                <span class="tenants-field__label">Default locale *</span>
                <select name="default_locale" required>
                    <option value="fi_FI" selected>fi_FI</option>
                    <option value="en_GB">en_GB</option>
                    <option value="sw_TZ">sw_TZ</option>
                </select>
            </label>

            <label class="tenants-field">
                <span class="tenants-field__label">Member-number prefix</span>
                <input type="text" name="member_number_prefix" pattern="[A-Z0-9\-]*" placeholder="DAEMS-">
            </label>

            <div class="tenants-modal__actions">
                <button type="button" class="btn btn--ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn--primary" id="tenants-new-submit">Create</button>
            </div>
            <div id="tenants-new-error" class="tenants-modal__error" aria-live="polite"></div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="/backstage/pages/platform/tenants/list.css">
<script src="/backstage/pages/platform/tenants/list.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
