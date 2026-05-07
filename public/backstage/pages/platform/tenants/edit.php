<?php
/**
 * Wave H — Tenant edit shell.
 *
 * Five-tab page (Basics, Domains, Admins, Modules, Danger zone) selected
 * via ?tab=. The router (public/backstage/router.php) matches
 * /backstage/platform/tenants/<id> via regex and forwards $tenantIdParam
 * to this template. Tab data is loaded by edit.js via the shared
 * /api/backstage/platform-tenants proxy.
 *
 * @var string $tenantIdParam  Tenant id from the URL path (set by router.php).
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

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

$pageTitle   = 'platform.tenants.title';
$activePage  = 'platform-tenants';
$breadcrumbs = [
    ['label' => I18n::t('platform.breadcrumb.platform')],
    ['label' => I18n::t('platform.tenants.title'), 'url' => '/backstage/platform/tenants'],
];

$tabs = [
    'basics'  => 'platform.tenants.tab.basics',
    'domains' => 'platform.tenants.tab.domains',
    'admins'  => 'platform.tenants.tab.admins',
    'modules' => 'platform.tenants.tab.modules',
    'danger'  => 'platform.tenants.tab.danger',
];

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title" id="tenant-edit-name"><?= I18n::e('platform.tenants.title') ?></h1>
        <p class="page-header__subtitle">
            <span class="tenant-edit-header-meta">
                <code id="tenant-edit-slug"><?= I18n::e('platform.common.empty_dash') ?></code>
                <span class="tenants-pill" id="tenant-edit-status"><?= I18n::e('platform.common.empty_dash') ?></span>
            </span>
        </p>
    </div>
    <div>
        <a href="/backstage/platform/tenants" class="btn btn--ghost">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            <?= I18n::e('platform.tenants.edit.back_to_list') ?>
        </a>
    </div>
</div>

<nav class="tenant-tabs" role="tablist" aria-label="<?= $esc(I18n::t('platform.tenants.edit.aria.tabs')) ?>">
    <?php foreach ($tabs as $key => $labelKey): ?>
        <a href="?tab=<?= $esc($key) ?>"
           role="tab"
           class="tenant-tabs__tab<?= $activeTab === $key ? ' is-active' : '' ?>"
           aria-selected="<?= $activeTab === $key ? 'true' : 'false' ?>"
           data-tab="<?= $esc($key) ?>"><?= I18n::e($labelKey) ?></a>
    <?php endforeach; ?>
</nav>

<div id="tenant-edit-status-line" class="tenants-status" aria-live="polite"><?= I18n::e('platform.common.loading') ?></div>

<div class="tenant-edit-content" id="tenant-edit-content">
    <!-- Per-tab content is rendered by edit.js into this host. The five
         <template> blocks below are cloned in when their tab activates. -->
</div>

<!-- ── Basics tab ──────────────────────────────────────────────────── -->
<template id="tab-tpl-basics">
    <form class="tenant-form" id="tenant-basics-form" autocomplete="off" novalidate>
        <div class="tenant-form__row">
            <label for="tb-slug"><?= I18n::e('platform.tenants.basics.slug_label') ?></label>
            <input type="text" name="slug" id="tb-slug" readonly>
        </div>

        <div class="tenant-form__row">
            <label><?= I18n::e('platform.tenants.field.display_name') ?></label>
            <small><?= I18n::e('platform.tenants.basics.translations_help') ?></small>
            <div class="tenant-locale-cards" id="tb-display-name-cards"></div>
        </div>

        <div class="tenant-form__row">
            <label><?= I18n::e('platform.tenants.field.public_description') ?></label>
            <div class="tenant-locale-cards" id="tb-public-description-cards"></div>
        </div>

        <div class="tenant-form__row">
            <label><?= I18n::e('platform.tenants.field.supported_locales') ?></label>
            <div class="tenant-form__locales" id="tb-supported-locales">
                <label><input type="checkbox" name="supportedLocales" value="fi_FI"> fi_FI</label>
                <label><input type="checkbox" name="supportedLocales" value="en_GB"> en_GB</label>
                <label><input type="checkbox" name="supportedLocales" value="sw_TZ"> sw_TZ</label>
            </div>
        </div>

        <div class="tenant-form__row">
            <label for="tb-default-locale"><?= I18n::e('platform.tenants.field.default_locale') ?></label>
            <select name="defaultLocale" id="tb-default-locale">
                <option value="fi_FI">fi_FI</option>
                <option value="en_GB">en_GB</option>
                <option value="sw_TZ">sw_TZ</option>
            </select>
        </div>

        <div class="tenant-form__row">
            <label for="tb-prefix"><?= I18n::e('platform.tenants.field.member_prefix') ?></label>
            <input type="text" name="memberNumberPrefix" id="tb-prefix" pattern="[A-Z0-9\-]*">
        </div>

        <div class="tenant-form__actions">
            <span class="tenant-form__status" id="tb-status" aria-live="polite"></span>
            <button type="submit" class="btn btn--primary" id="tb-save"><?= I18n::e('platform.common.save') ?></button>
        </div>
    </form>
</template>

<!-- ── Domains tab ─────────────────────────────────────────────────── -->
<template id="tab-tpl-domains">
    <div class="tenant-tab-toolbar">
        <span class="tenants-status" id="td-status"><?= I18n::e('platform.common.loading') ?></span>
        <button type="button" class="btn btn--primary btn--sm" id="td-add-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= I18n::e('platform.tenants.domains.add') ?>
        </button>
    </div>
    <table class="tenant-tab-table" id="td-table" hidden>
        <thead>
            <tr>
                <th><?= I18n::e('platform.tenants.domains.col.hostname') ?></th>
                <th><?= I18n::e('platform.tenants.domains.col.primary') ?></th>
                <th><?= I18n::e('platform.tenants.domains.col.created') ?></th>
                <th class="tenant-tab-table__actions" aria-label="actions"></th>
            </tr>
        </thead>
        <tbody id="td-tbody"></tbody>
    </table>

    <div class="tenant-tab-empty" id="td-empty" hidden>
        <span class="tenant-tab-empty__icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
        </span>
        <h3 class="tenant-tab-empty__title"><?= I18n::e('platform.tenants.domains.empty.title') ?></h3>
        <p class="tenant-tab-empty__body"><?= I18n::e('platform.tenants.domains.empty.body') ?></p>
    </div>

    <div class="tenants-modal" id="td-add-modal" hidden role="dialog" aria-modal="true" aria-labelledby="td-add-title">
        <div class="tenants-modal__backdrop" data-close></div>
        <div class="tenants-modal__panel" role="document">
            <header class="tenants-modal__header">
                <h2 id="td-add-title" class="tenants-modal__title"><?= I18n::e('platform.tenants.domains.add') ?></h2>
                <button type="button" class="tenants-modal__close" data-close aria-label="<?= $esc(I18n::t('platform.common.close')) ?>">&times;</button>
            </header>
            <form id="td-add-form" class="tenants-modal__form" novalidate>
                <label class="tenants-field">
                    <span class="tenants-field__label"><?= I18n::e('platform.tenants.domains.field.hostname') ?> *</span>
                    <input type="text" name="hostname" required placeholder="example.org" autocomplete="off">
                </label>
                <fieldset class="tenants-field">
                    <label><input type="checkbox" name="isPrimary"> <?= I18n::e('platform.tenants.domains.field.is_primary') ?></label>
                </fieldset>
                <div id="td-add-error" class="tenants-modal__error" aria-live="polite"></div>
                <div class="tenants-modal__actions">
                    <button type="button" class="btn btn--ghost" data-close><?= I18n::e('platform.common.cancel') ?></button>
                    <button type="submit" class="btn btn--primary"><?= I18n::e('platform.tenants.admins.action.grant') ?></button>
                </div>
            </form>
        </div>
    </div>
</template>

<!-- ── Admins tab ──────────────────────────────────────────────────── -->
<template id="tab-tpl-admins">
    <div class="tenant-tab-toolbar">
        <span class="tenants-status">
            <?= I18n::e('platform.tenants.admins.total_label') ?>:
            <strong id="ta-count"><?= I18n::e('platform.common.empty_dash') ?></strong>
        </span>
        <button type="button" class="btn btn--primary btn--sm" id="ta-add-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <line x1="19" y1="8" x2="19" y2="14"/>
                <line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            <?= I18n::e('platform.tenants.admins.add') ?>
        </button>
    </div>

    <div class="tenants-status" id="ta-status"></div>
    <div class="tenant-tab-placeholder" id="ta-empty" hidden>
        <strong><?= I18n::e('platform.tenants.admins.empty_title') ?></strong>
        <small><?= I18n::e('platform.tenants.admins.empty_body') ?></small>
    </div>

    <table class="tenant-tab-table" id="ta-table" hidden>
        <thead>
            <tr>
                <th><?= I18n::e('platform.tenants.admins.col.name') ?></th>
                <th><?= I18n::e('platform.tenants.admins.col.email') ?></th>
                <th><?= I18n::e('platform.tenants.admins.col.granted_at') ?></th>
                <th class="tenant-tab-table__actions" aria-label="actions"></th>
            </tr>
        </thead>
        <tbody id="ta-tbody"></tbody>
    </table>

    <div class="tenants-modal" id="ta-add-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ta-add-title">
        <div class="tenants-modal__backdrop" data-close></div>
        <div class="tenants-modal__panel" role="document">
            <header class="tenants-modal__header">
                <h2 id="ta-add-title" class="tenants-modal__title"><?= I18n::e('platform.tenants.admins.add') ?></h2>
                <button type="button" class="tenants-modal__close" data-close aria-label="<?= $esc(I18n::t('platform.common.close')) ?>">&times;</button>
            </header>
            <form id="ta-add-form" class="tenants-modal__form" novalidate>
                <label class="tenants-field">
                    <span class="tenants-field__label"><?= I18n::e('platform.tenants.admins.field.user_id') ?> *</span>
                    <input type="text" name="userId" required placeholder="user id (UUID)" autocomplete="off">
                    <small class="tenants-field__hint"><?= I18n::e('platform.tenants.admins.field.user_id.hint') ?></small>
                </label>
                <div id="ta-add-error" class="tenants-modal__error" aria-live="polite"></div>
                <div class="tenants-modal__actions">
                    <button type="button" class="btn btn--ghost" data-close><?= I18n::e('platform.common.cancel') ?></button>
                    <button type="submit" class="btn btn--primary"><?= I18n::e('platform.tenants.admins.action.grant') ?></button>
                </div>
            </form>
        </div>
    </div>
</template>

<!-- ── Modules tab ─────────────────────────────────────────────────── -->
<template id="tab-tpl-modules">
    <div class="tenant-tab-toolbar">
        <span class="tenants-status" id="tm-status"><?= I18n::e('platform.common.loading') ?></span>
    </div>
    <table class="tenant-tab-table" id="tm-table" hidden>
        <thead>
            <tr>
                <th><?= I18n::e('platform.tenants.modules.col.slug') ?></th>
                <th><?= I18n::e('platform.tenants.modules.col.name') ?></th>
                <th><?= I18n::e('platform.tenants.modules.col.category') ?></th>
                <th><?= I18n::e('platform.tenants.modules.col.state') ?></th>
                <th class="tenant-tab-table__actions" aria-label="actions"></th>
            </tr>
        </thead>
        <tbody id="tm-tbody"></tbody>
    </table>

    <div class="tenant-confirm" id="tm-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="tm-confirm-title">
        <div class="tenant-confirm__backdrop" data-close></div>
        <div class="tenant-confirm__panel" role="document">
            <h3 class="tenant-confirm__title" id="tm-confirm-title"><?= I18n::e('platform.tenants.modules.action.revoke') ?></h3>
            <p class="tenant-confirm__body" id="tm-confirm-body"></p>
            <div class="tenant-confirm__field">
                <label for="tm-reason"><?= I18n::e('platform.tenants.modules.field.reason') ?> *</label>
                <textarea id="tm-reason" rows="3" required></textarea>
            </div>
            <div class="tenant-confirm__actions">
                <button type="button" class="btn btn--ghost" data-close><?= I18n::e('platform.common.cancel') ?></button>
                <button type="button" class="btn btn--primary" id="tm-confirm-go"><?= I18n::e('platform.tenants.modules.action.revoke') ?></button>
            </div>
        </div>
    </div>
</template>

<!-- ── Danger zone tab ─────────────────────────────────────────────── -->
<template id="tab-tpl-danger">
    <div class="tenant-danger-stack">
        <!-- Suspend card (shown when active) -->
        <div class="tenant-danger-card tenant-danger-card--suspend" id="td-suspend-card" hidden>
            <div class="tenant-danger-card__head">
                <span class="tenant-danger-card__icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </span>
                <h3><?= I18n::e('platform.tenants.danger.suspend.title') ?></h3>
            </div>
            <p><?= I18n::e('platform.tenants.danger.suspend.body') ?></p>
            <form id="td-suspend-form" class="tenant-form">
                <div class="tenant-form__row">
                    <label for="td-suspend-reason"><?= I18n::e('platform.tenants.danger.suspend.field') ?> *</label>
                    <textarea id="td-suspend-reason" name="reason" rows="3" required
                              placeholder="<?= $esc(I18n::t('platform.tenants.danger.suspend.placeholder')) ?>"></textarea>
                </div>
                <div class="tenant-form__actions">
                    <button type="submit" class="btn btn--danger"><?= I18n::e('platform.tenants.danger.suspend.action') ?></button>
                </div>
            </form>
        </div>

        <!-- Reactivate card (shown when suspended) -->
        <div class="tenant-danger-card tenant-danger-card--reactivate" id="td-reactivate-card" hidden>
            <div class="tenant-danger-card__head">
                <span class="tenant-danger-card__icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                </span>
                <h3><?= I18n::e('platform.tenants.danger.reactivate.title') ?></h3>
            </div>
            <p id="td-reactivate-reason-line"><?= I18n::e('platform.tenants.danger.reactivate.body') ?></p>
            <div class="tenant-form__actions">
                <button type="button" class="btn btn--success" id="td-reactivate-btn"><?= I18n::e('platform.tenants.danger.reactivate.action') ?></button>
            </div>
        </div>

        <!-- Hard-delete (out of scope) -->
        <div class="tenant-danger-card tenant-danger-card--info">
            <div class="tenant-danger-card__head">
                <span class="tenant-danger-card__icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                    </svg>
                </span>
                <h3><?= I18n::e('platform.tenants.danger.delete.title') ?></h3>
            </div>
            <p><?= I18n::e('platform.tenants.danger.delete.body') ?></p>
        </div>
    </div>
</template>

<script>
window.DAEMS_TENANT_EDIT = {
    tenantId:  <?= json_encode($tenantId, JSON_UNESCAPED_SLASHES) ?>,
    activeTab: <?= json_encode($activeTab, JSON_UNESCAPED_SLASHES) ?>
};
window.DAEMS_TENANTS_I18N = Object.assign(window.DAEMS_TENANTS_I18N || {}, {
    'platform.common.loading':                       <?= json_encode(I18n::t('platform.common.loading'),                       JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.error_prefix':                  <?= json_encode(I18n::t('platform.common.error_prefix'),                  JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.network_error':                 <?= json_encode(I18n::t('platform.common.network_error'),                 JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.basics.toast.saved':           <?= json_encode(I18n::t('platform.tenants.basics.toast.saved'),           JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.basics.toast.save_failed':     <?= json_encode(I18n::t('platform.tenants.basics.toast.save_failed'),     JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.basics.status.saving':         <?= json_encode(I18n::t('platform.tenants.basics.status.saving'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.basics.status.saved':          <?= json_encode(I18n::t('platform.tenants.basics.status.saved'),          JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.toast.added':          <?= json_encode(I18n::t('platform.tenants.domains.toast.added'),          JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.toast.removed':        <?= json_encode(I18n::t('platform.tenants.domains.toast.removed'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.toast.primary_updated':<?= json_encode(I18n::t('platform.tenants.domains.toast.primary_updated'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.confirm_remove':       <?= json_encode(I18n::t('platform.tenants.domains.confirm_remove'),       JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.cannot_remove_primary':<?= json_encode(I18n::t('platform.tenants.domains.cannot_remove_primary'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.action.make_primary':  <?= json_encode(I18n::t('platform.tenants.domains.action.make_primary'),  JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.action.remove':        <?= json_encode(I18n::t('platform.tenants.domains.action.remove'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.pill.primary':         <?= json_encode(I18n::t('platform.tenants.domains.pill.primary'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.empty.title':          <?= json_encode(I18n::t('platform.tenants.domains.empty.title'),          JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.domains.empty.body':           <?= json_encode(I18n::t('platform.tenants.domains.empty.body'),           JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.toast.granted':         <?= json_encode(I18n::t('platform.tenants.admins.toast.granted'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.toast.revoked':         <?= json_encode(I18n::t('platform.tenants.admins.toast.revoked'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.confirm_revoke':        <?= json_encode(I18n::t('platform.tenants.admins.confirm_revoke'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.error.user_id_required':<?= json_encode(I18n::t('platform.tenants.admins.error.user_id_required'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.empty_title':           <?= json_encode(I18n::t('platform.tenants.admins.empty_title'),           JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.admins.action.revoke':         <?= json_encode(I18n::t('platform.tenants.admins.action.revoke'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.state.enabled':        <?= json_encode(I18n::t('platform.tenants.modules.state.enabled'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.state.available':      <?= json_encode(I18n::t('platform.tenants.modules.state.available'),      JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.state.disabled':       <?= json_encode(I18n::t('platform.tenants.modules.state.disabled'),       JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.state.core':           <?= json_encode(I18n::t('platform.tenants.modules.state.core'),           JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.action.grant':         <?= json_encode(I18n::t('platform.tenants.modules.action.grant'),         JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.action.revoke':        <?= json_encode(I18n::t('platform.tenants.modules.action.revoke'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.confirm.grant.title':  <?= json_encode(I18n::t('platform.tenants.modules.confirm.grant.title'),  JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.confirm.grant.body':   <?= json_encode(I18n::t('platform.tenants.modules.confirm.grant.body'),   JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.confirm.revoke.title': <?= json_encode(I18n::t('platform.tenants.modules.confirm.revoke.title'), JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.confirm.revoke.body':  <?= json_encode(I18n::t('platform.tenants.modules.confirm.revoke.body'),  JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.error.reason_required':<?= json_encode(I18n::t('platform.tenants.modules.error.reason_required'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.toast.granted':        <?= json_encode(I18n::t('platform.tenants.modules.toast.granted'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.toast.revoked':        <?= json_encode(I18n::t('platform.tenants.modules.toast.revoked'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.modules.empty':                <?= json_encode(I18n::t('platform.tenants.modules.empty'),                JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.suspend.confirm_body':  <?= json_encode(I18n::t('platform.tenants.danger.suspend.confirm_body'),  JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.reactivate.confirm_body':<?= json_encode(I18n::t('platform.tenants.danger.reactivate.confirm_body'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.reactivate.with_reason':<?= json_encode(I18n::t('platform.tenants.danger.reactivate.with_reason'),JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.toast.suspended':       <?= json_encode(I18n::t('platform.tenants.danger.toast.suspended'),       JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.toast.reactivated':     <?= json_encode(I18n::t('platform.tenants.danger.toast.reactivated'),     JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.danger.error.reason_required': <?= json_encode(I18n::t('platform.tenants.danger.error.reason_required'), JSON_UNESCAPED_UNICODE) ?>
});
</script>

<link rel="stylesheet" href="/backstage/pages/platform/tenants/list.css">
<link rel="stylesheet" href="/backstage/pages/platform/tenants/edit.css">
<script src="/backstage/pages/platform/tenants/edit.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
