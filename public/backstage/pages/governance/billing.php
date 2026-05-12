<?php
/**
 * Backstage Governance — Laskutus (Billing)
 *
 * Year picker + fee table view (Wave B).
 * Data source: GET /api/backstage/governance/billing/fee-schedules?year=YYYY
 * Create/update: POST /api/backstage/governance/billing/fee-schedules
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.billing';
$activePage  = 'governance-billing';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.billing')],
];

$year     = isset($_GET['year']) ? (int) $_GET['year'] : ((int) date('Y') + 1);
$editMode = ($_GET['edit'] ?? '') === '1';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.billing') ?> — <?= (int) $year ?></h1>
    </div>
    <form method="get" class="billing-year-form">
        <label for="billing-year"><?= I18n::e('backstage.governance.billing.year_label') ?></label>
        <input id="billing-year" type="number" name="year" min="2026" max="2099" value="<?= (int) $year ?>">
        <button type="submit" class="btn btn--ghost btn--sm"><?= I18n::e('backstage.common.apply') ?></button>
        <a class="btn btn--ghost btn--sm" href="/backstage/governance/billing/invoices">
            <?= I18n::e('backstage.governance.billing.invoices.link') ?> &rarr;
        </a>
        <a class="btn btn--ghost btn--sm" href="/backstage/governance/billing/overrides">
            <?= I18n::e('backstage.governance.billing.overrides.link') ?> &rarr;
        </a>
    </form>
</div>

<?php if ($editMode): ?>
    <section class="billing-editor">
        <form id="billing-form" method="post" data-redirect="/backstage/governance/billing?year=<?= (int) $year ?>">
            <input type="hidden" name="year" value="<?= (int) $year ?>">
            <fieldset>
                <legend><?= I18n::e('backstage.governance.billing.editor_legend') ?> <?= (int) $year ?></legend>
                <label>SUPPORTING (€)
                    <input type="number" min="0" step="1" name="supporting" value="0" required>
                </label>
                <label>BASIC (€)
                    <input type="number" min="0" step="1" name="basic" value="0" required>
                </label>
                <label>FULL (€)
                    <input type="number" min="0" step="1" name="full" value="0" required>
                </label>
            </fieldset>
            <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.common.save') ?></button>
            <a class="btn btn--ghost" href="?year=<?= (int) $year ?>"><?= I18n::e('backstage.common.cancel') ?></a>
            <p class="billing-editor__note"><?= I18n::e('backstage.governance.billing.editor_note') ?></p>
        </form>
    </section>
<?php else: ?>
    <section class="billing-list">
        <table class="billing-table" data-year="<?= (int) $year ?>">
            <thead>
                <tr>
                    <th><?= I18n::e('backstage.governance.billing.col.fee_type') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.col.amount') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.col.status') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.col.activated') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.col.decision') ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5"><?= I18n::e('backstage.common.loading') ?></td></tr>
            </tbody>
        </table>
        <a class="btn btn--primary" href="?year=<?= (int) $year ?>&edit=1">
            <?= I18n::e('backstage.governance.billing.edit_button') ?>
        </a>
    </section>
<?php endif; ?>

<link rel="stylesheet" href="/backstage/pages/governance/billing.css">
<script src="/backstage/pages/governance/billing.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
