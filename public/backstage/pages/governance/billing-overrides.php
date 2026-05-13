<?php
/**
 * Backstage Governance — Per-jäsen alennukset (User fee overrides)
 *
 * List + create + revoke admin UI for UserFeeOverride entities.
 * Data source: GET /api/backstage/governance/billing/overrides[?active_only=1]
 * Create: POST /api/backstage/governance/billing/overrides
 * Revoke: POST /api/backstage/governance/billing/overrides/{id}/revoke
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.billing.overrides';
$activePage  = 'governance-billing';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.billing'), 'href' => '/backstage/governance/billing'],
    ['label' => I18n::t('backstage.governance.billing.overrides.link')],
];

$activeOnly = ($_GET['active_only'] ?? '0') === '1';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.billing.overrides.heading') ?></h1>
    </div>
    <div class="billing-overrides__filters">
        <label class="billing-overrides__active-toggle">
            <input type="checkbox" id="active-only-toggle" <?= $activeOnly ? 'checked' : '' ?>>
            <?= I18n::e('backstage.governance.billing.overrides.active_only') ?>
        </label>
        <button type="button" id="new-override-btn" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.overrides.new_button') ?></button>
    </div>
</div>

<section class="billing-overrides">
    <table class="billing-overrides__list" data-active-only="<?= $activeOnly ? '1' : '0' ?>">
        <thead>
            <tr>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.user') ?></th>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.fee_type') ?></th>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.amount') ?></th>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.window') ?></th>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.reason') ?></th>
                <th><?= I18n::e('backstage.governance.billing.overrides.col.actions') ?></th>
            </tr>
        </thead>
        <tbody><tr><td colspan="6"><?= I18n::e('backstage.common.loading') ?></td></tr></tbody>
    </table>
</section>

<dialog id="new-override-dialog" class="billing-overrides__dialog">
    <form id="new-override-form">
        <h3><?= I18n::e('backstage.governance.billing.overrides.dialog.title') ?></h3>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.user_id') ?>
            <div class="member-picker" data-target="user_id">
                <input type="text" class="member-picker__search" placeholder="Hae nimellä tai sähköpostilla&hellip;" autocomplete="off" required>
                <ul class="member-picker__suggestions" hidden></ul>
                <input type="hidden" name="user_id" required>
                <p class="member-picker__selected" hidden></p>
            </div>
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.fee_type') ?>
            <select name="fee_type" required>
                <option value="SUPPORTING">SUPPORTING</option>
                <option value="BASIC" selected>BASIC</option>
                <option value="FULL">FULL</option>
            </select>
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.amount_euro') ?>
            <input type="number" name="override_amount_euro" min="0" step="0.01" required>
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.valid_from') ?>
            <input type="date" name="valid_from" required>
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.valid_until') ?>
            <input type="date" name="valid_until">
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.reason') ?>
            <textarea name="reason" rows="3" required></textarea>
        </label>
        <label><?= I18n::e('backstage.governance.billing.overrides.dialog.decision_id') ?>
            <input type="text" name="decision_id" pattern="[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}">
        </label>
        <menu class="billing-overrides__dialog-actions">
            <button type="button" class="btn btn--ghost" data-action="cancel"><?= I18n::e('backstage.governance.billing.overrides.dialog.cancel') ?></button>
            <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.overrides.dialog.save') ?></button>
        </menu>
    </form>
</dialog>

<link rel="stylesheet" href="/backstage/pages/governance/billing-overrides.css">
<script
    src="/backstage/pages/governance/billing-overrides.js"
    data-revoke-confirm="<?= I18n::e('backstage.governance.billing.overrides.revoke_confirm') ?>"
    data-revoke-button="<?= I18n::e('backstage.governance.billing.overrides.revoke_button') ?>"
    data-revoked-badge="<?= I18n::e('backstage.governance.billing.overrides.revoked_badge') ?>"
    data-empty-text="<?= I18n::e('backstage.governance.billing.overrides.empty') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
