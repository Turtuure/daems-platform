<?php
/**
 * Wave H — Settings → Modules.
 *
 * Tenant-admin-facing page that lists every module the tenant's plan
 * grants them and lets the admin enable/disable them.
 *
 * Data source: /api/backstage/tenant-modules?op=list (proxy →
 * /api/v1/backstage/tenant/modules). Toggle action is op=state with
 * body {action:'enable'|'disable'}.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'settings.modules.title';
$activePage  = 'settings';
$breadcrumbs = [
    ['label' => I18n::t('backstage.settings.title'), 'url' => '/backstage/settings'],
    ['label' => I18n::t('settings.modules.title')],
];

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('settings.modules.title') ?></h1>
        <p class="page-header__subtitle"><?= I18n::e('settings.modules.subtitle') ?></p>
    </div>
</div>

<div id="modules-status" class="settings-modules-status" aria-live="polite"><?= I18n::e('platform.common.loading') ?></div>

<section class="settings-modules-section" aria-labelledby="mod-enabled-h">
    <h2 id="mod-enabled-h" class="settings-modules-section__title">
        <?= I18n::e('settings.modules.enabled') ?>
        <span class="settings-modules-section__title-count" id="mod-enabled-count" hidden>0</span>
    </h2>
    <div class="settings-modules-grid" id="modules-enabled"></div>
</section>

<section class="settings-modules-section" aria-labelledby="mod-available-h">
    <h2 id="mod-available-h" class="settings-modules-section__title">
        <?= I18n::e('settings.modules.available') ?>
        <span class="settings-modules-section__title-count" id="mod-available-count" hidden>0</span>
    </h2>
    <div class="settings-modules-grid" id="modules-available"></div>
</section>

<section class="settings-modules-section" id="modules-disabled-section" hidden aria-labelledby="mod-disabled-h">
    <h2 id="mod-disabled-h" class="settings-modules-section__title">
        <?= I18n::e('settings.modules.disabled') ?>
        <span class="settings-modules-section__title-count" id="mod-disabled-count" hidden>0</span>
    </h2>
    <div class="settings-modules-grid" id="modules-disabled"></div>
</section>

<script>
window.DAEMS_TENANT_MODULES_I18N = {
    'settings.modules.activate':           <?= json_encode(I18n::t('settings.modules.activate'),           JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.deactivate':         <?= json_encode(I18n::t('settings.modules.deactivate'),         JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.confirm_deactivate': <?= json_encode(I18n::t('settings.modules.confirm_deactivate'), JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.empty':              <?= json_encode(I18n::t('settings.modules.empty'),              JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.empty.available':    <?= json_encode(I18n::t('settings.modules.empty.available'),    JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.empty.enabled':      <?= json_encode(I18n::t('settings.modules.empty.enabled'),      JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.granted_at':         <?= json_encode(I18n::t('settings.modules.granted_at'),         JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.toast.activated':    <?= json_encode(I18n::t('settings.modules.toast.activated'),    JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.toast.deactivated':  <?= json_encode(I18n::t('settings.modules.toast.deactivated'),  JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.toast.activate_failed':   <?= json_encode(I18n::t('settings.modules.toast.activate_failed'),   JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.toast.deactivate_failed': <?= json_encode(I18n::t('settings.modules.toast.deactivate_failed'), JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.error.load_failed':  <?= json_encode(I18n::t('settings.modules.error.load_failed'),  JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.pill.enabled':       <?= json_encode(I18n::t('settings.modules.pill.enabled'),       JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.pill.available':     <?= json_encode(I18n::t('settings.modules.pill.available'),     JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.pill.disabled':      <?= json_encode(I18n::t('settings.modules.pill.disabled'),      JSON_UNESCAPED_UNICODE) ?>,
    'settings.modules.pill.core':          <?= json_encode(I18n::t('settings.modules.pill.core'),          JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.network_error':       <?= json_encode(I18n::t('platform.common.network_error'),       JSON_UNESCAPED_UNICODE) ?>,
    'platform.common.error_prefix':        <?= json_encode(I18n::t('platform.common.error_prefix'),        JSON_UNESCAPED_UNICODE) ?>
};
</script>

<link rel="stylesheet" href="/backstage/pages/settings/modules/modules.css">
<script src="/backstage/pages/settings/modules/modules.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
