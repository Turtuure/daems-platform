<?php
/**
 * Wave H — Platform Tenants list page.
 *
 * GSAs view + create tenants here. Data source:
 *   /api/backstage/platform-tenants?op=list
 *   (proxy → /api/v1/backstage/platform/tenants)
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'platform.tenants.title';
$activePage  = 'platform-tenants';
$breadcrumbs = [
    ['label' => I18n::t('platform.breadcrumb.platform')],
    ['label' => I18n::t('platform.tenants.title')],
];

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('platform.tenants.title') ?></h1>
        <p class="page-header__subtitle"><?= I18n::e('platform.tenants.subtitle') ?></p>
    </div>
    <div>
        <button type="button" class="btn btn--primary" id="tenants-new-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= I18n::e('platform.tenants.create') ?>
        </button>
    </div>
</div>

<div class="tenants-toolbar">
    <input type="search" id="tenants-search" class="tenants-toolbar__search"
           placeholder="<?= $esc(I18n::t('platform.tenants.search.placeholder')) ?>"
           aria-label="<?= $esc(I18n::t('platform.tenants.search.placeholder')) ?>">
    <select id="tenants-status-filter" class="tenants-toolbar__filter"
            aria-label="<?= $esc(I18n::t('platform.tenants.filter.status_label')) ?>">
        <option value=""><?= I18n::e('platform.tenants.filter.all_statuses') ?></option>
        <option value="active"><?= I18n::e('platform.tenants.status.active') ?></option>
        <option value="suspended"><?= I18n::e('platform.tenants.status.suspended') ?></option>
    </select>
</div>

<div class="card">
    <div class="card__body">
        <div id="tenants-status" class="tenants-status" aria-live="polite"><?= I18n::e('platform.common.loading') ?></div>
        <table class="tenants-table" id="tenants-table" hidden>
            <thead>
                <tr>
                    <th><?= I18n::e('platform.tenants.col.slug') ?></th>
                    <th><?= I18n::e('platform.tenants.col.name') ?></th>
                    <th><?= I18n::e('platform.tenants.col.status') ?></th>
                    <th class="tenants-table__num"><?= I18n::e('platform.tenants.col.domains') ?></th>
                    <th class="tenants-table__num"><?= I18n::e('platform.tenants.col.members') ?></th>
                    <th class="tenants-table__num"><?= I18n::e('platform.tenants.col.admins') ?></th>
                    <th class="tenants-table__num"><?= I18n::e('platform.tenants.col.modules') ?></th>
                </tr>
            </thead>
            <tbody id="tenants-tbody"></tbody>
        </table>

        <div id="tenants-empty" class="tenants-empty" hidden>
            <span class="tenants-empty__icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 21h18"/>
                    <path d="M5 21V7l8-4v18"/>
                    <path d="M19 21V11l-6-4"/>
                    <path d="M9 9h0M9 13h0M9 17h0"/>
                </svg>
            </span>
            <h2 class="tenants-empty__title"><?= I18n::e('platform.tenants.empty.title') ?></h2>
            <p class="tenants-empty__body"><?= I18n::e('platform.tenants.empty.body') ?></p>
            <div class="tenants-empty__actions">
                <button type="button" class="btn btn--primary" id="tenants-new-btn-empty">
                    <?= I18n::e('platform.tenants.create') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- New-tenant modal -->
<div class="tenants-modal" id="tenants-new-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tenants-new-title">
    <div class="tenants-modal__backdrop" data-close></div>
    <div class="tenants-modal__panel" role="document">
        <header class="tenants-modal__header">
            <h2 id="tenants-new-title" class="tenants-modal__title"><?= I18n::e('platform.tenants.create') ?></h2>
            <button type="button" class="tenants-modal__close" data-close aria-label="<?= $esc(I18n::t('platform.common.close')) ?>">&times;</button>
        </header>
        <form id="tenants-new-form" class="tenants-modal__form" novalidate>
            <label class="tenants-field">
                <span class="tenants-field__label"><?= I18n::e('platform.tenants.field.slug') ?> *</span>
                <input type="text" name="slug" required pattern="[a-z][a-z0-9-]*" placeholder="my-society" autocomplete="off">
                <small class="tenants-field__hint"><?= I18n::e('platform.tenants.field.slug.hint') ?></small>
            </label>

            <label class="tenants-field">
                <span class="tenants-field__label"><?= I18n::e('platform.tenants.field.display_name') ?> (en_GB) *</span>
                <input type="text" name="display_name_en" required placeholder="My Society" autocomplete="off">
            </label>

            <fieldset class="tenants-field">
                <legend class="tenants-field__label"><?= I18n::e('platform.tenants.field.supported_locales') ?> *</legend>
                <label><input type="checkbox" name="locales[]" value="fi_FI" checked> fi_FI</label>
                <label><input type="checkbox" name="locales[]" value="en_GB" checked> en_GB</label>
                <label><input type="checkbox" name="locales[]" value="sw_TZ"> sw_TZ</label>
            </fieldset>

            <label class="tenants-field">
                <span class="tenants-field__label"><?= I18n::e('platform.tenants.field.default_locale') ?> *</span>
                <select name="default_locale" required>
                    <option value="fi_FI" selected>fi_FI</option>
                    <option value="en_GB">en_GB</option>
                    <option value="sw_TZ">sw_TZ</option>
                </select>
            </label>

            <label class="tenants-field">
                <span class="tenants-field__label"><?= I18n::e('platform.tenants.field.member_prefix') ?></span>
                <input type="text" name="member_number_prefix" pattern="[A-Z0-9\-]*" placeholder="DAEMS-" autocomplete="off">
            </label>

            <div id="tenants-new-error" class="tenants-modal__error" aria-live="polite"></div>

            <div class="tenants-modal__actions">
                <button type="button" class="btn btn--ghost" data-close><?= I18n::e('platform.common.cancel') ?></button>
                <button type="submit" class="btn btn--primary" id="tenants-new-submit"><?= I18n::e('platform.common.create') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
window.DAEMS_TENANTS_I18N = {
    'platform.tenants.empty.no_matches':    <?= json_encode(I18n::t('platform.tenants.empty.no_matches'),    JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.error.load_failed':   <?= json_encode(I18n::t('platform.tenants.error.load_failed'),   JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.toast.created':       <?= json_encode(I18n::t('platform.tenants.toast.created'),       JSON_UNESCAPED_UNICODE) ?>,
    'platform.tenants.toast.create_failed': <?= json_encode(I18n::t('platform.tenants.toast.create_failed'), JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.network_error':        <?= json_encode(I18n::t('platform.common.network_error'),        JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.loading':              <?= json_encode(I18n::t('platform.common.loading'),              JSON_UNESCAPED_UNICODE) ?>
};
</script>
<link rel="stylesheet" href="/backstage/pages/platform/tenants/list.css">
<script src="/backstage/pages/platform/tenants/list.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
