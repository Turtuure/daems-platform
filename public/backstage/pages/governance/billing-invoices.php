<?php
/**
 * Backstage Governance — Laskut (Invoices)
 *
 * Filterable list + row actions: mark-paid, waive, reduce, audit.
 * Data source: GET /api/backstage/governance/billing/invoices[?year=&status=&fee_type=]
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.billing.invoices';
$activePage  = 'governance-billing';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.billing'), 'href' => '/backstage/governance/billing'],
    ['label' => I18n::t('backstage.governance.billing.invoices.link')],
];

$year    = isset($_GET['year'])     ? (int) $_GET['year']    : (int) date('Y');
$status  = isset($_GET['status'])   ? (string) $_GET['status']   : '';
$feeType = isset($_GET['fee_type']) ? (string) $_GET['fee_type'] : '';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.billing.invoices.heading') ?> — <?= (int) $year ?></h1>
    </div>
    <form method="get" class="billing-invoices__filters">
        <label><?= I18n::e('backstage.governance.billing.invoices.filter.year') ?>
            <input type="number" name="year" min="2026" max="2099" value="<?= (int) $year ?>">
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.filter.status') ?>
            <select name="status">
                <option value=""><?= I18n::e('backstage.governance.billing.invoices.filter.all') ?></option>
                <?php foreach (['PENDING','PAID','OVERDUE','WAIVED','REDUCED'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= I18n::e('backstage.governance.billing.invoices.status.' . $s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.filter.fee_type') ?>
            <select name="fee_type">
                <option value=""><?= I18n::e('backstage.governance.billing.invoices.filter.all') ?></option>
                <option value="SUPPORTING" <?= $feeType === 'SUPPORTING' ? 'selected' : '' ?>>SUPPORTING</option>
                <option value="BASIC"      <?= $feeType === 'BASIC'      ? 'selected' : '' ?>>BASIC</option>
                <option value="FULL"       <?= $feeType === 'FULL'       ? 'selected' : '' ?>>FULL</option>
            </select>
        </label>
        <button type="submit" class="btn btn--ghost btn--sm"><?= I18n::e('backstage.governance.billing.invoices.filter.submit') ?></button>
    </form>
</div>

<section class="billing-invoices">
    <table class="billing-invoices__list"
           data-year="<?= (int) $year ?>"
           data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
           data-fee-type="<?= htmlspecialchars($feeType, ENT_QUOTES) ?>">
        <thead>
            <tr>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.user') ?></th>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.fee_type') ?></th>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.amount') ?></th>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.due_date') ?></th>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.status') ?></th>
                <th><?= I18n::e('backstage.governance.billing.invoices.col.actions') ?></th>
            </tr>
        </thead>
        <tbody><tr><td colspan="6"><?= I18n::e('backstage.common.loading') ?></td></tr></tbody>
    </table>
</section>

<dialog id="mark-paid-dialog" class="billing-invoices__dialog">
    <form id="mark-paid-form">
        <h3><?= I18n::e('backstage.governance.billing.invoices.paid_dialog.title') ?></h3>
        <input type="hidden" name="invoice_id">
        <label><?= I18n::e('backstage.governance.billing.invoices.paid_dialog.amount') ?>
            <input type="number" name="amount_euro" min="0" step="0.01" required>
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.paid_dialog.paid_at') ?>
            <input type="datetime-local" name="paid_at" required>
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.paid_dialog.method') ?>
            <select name="method" required>
                <option value="bank_transfer"><?= I18n::e('backstage.governance.billing.invoices.method.bank_transfer') ?></option>
                <option value="cash"><?= I18n::e('backstage.governance.billing.invoices.method.cash') ?></option>
                <option value="other"><?= I18n::e('backstage.governance.billing.invoices.method.other') ?></option>
            </select>
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.paid_dialog.reference') ?>
            <input type="text" name="reference">
        </label>
        <menu>
            <button type="button" class="btn btn--ghost" data-action="cancel"><?= I18n::e('backstage.governance.billing.invoices.dialog.cancel') ?></button>
            <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.invoices.dialog.confirm') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="waive-dialog" class="billing-invoices__dialog">
    <form id="waive-form">
        <h3><?= I18n::e('backstage.governance.billing.invoices.waive_dialog.title') ?></h3>
        <input type="hidden" name="invoice_id">
        <label><?= I18n::e('backstage.governance.billing.invoices.waive_dialog.reason') ?>
            <textarea name="reason" rows="3" required></textarea>
        </label>
        <menu>
            <button type="button" class="btn btn--ghost" data-action="cancel"><?= I18n::e('backstage.governance.billing.invoices.dialog.cancel') ?></button>
            <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.invoices.action.waive') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="reduce-dialog" class="billing-invoices__dialog">
    <form id="reduce-form">
        <h3><?= I18n::e('backstage.governance.billing.invoices.reduce_dialog.title') ?></h3>
        <input type="hidden" name="invoice_id">
        <label><?= I18n::e('backstage.governance.billing.invoices.reduce_dialog.amount') ?>
            <input type="number" name="amount_euro" min="0" step="0.01" required>
        </label>
        <label><?= I18n::e('backstage.governance.billing.invoices.reduce_dialog.reason') ?>
            <textarea name="reason" rows="3" required></textarea>
        </label>
        <menu>
            <button type="button" class="btn btn--ghost" data-action="cancel"><?= I18n::e('backstage.governance.billing.invoices.dialog.cancel') ?></button>
            <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.invoices.action.reduce') ?></button>
        </menu>
    </form>
</dialog>

<dialog id="audit-dialog" class="billing-invoices__dialog">
    <h3><?= I18n::e('backstage.governance.billing.invoices.audit_dialog.title') ?></h3>
    <ul id="audit-list"></ul>
    <menu>
        <button type="button" class="btn btn--ghost" data-action="close-audit"><?= I18n::e('backstage.governance.billing.invoices.audit_dialog.close') ?></button>
    </menu>
</dialog>

<link rel="stylesheet" href="/backstage/pages/governance/billing-invoices.css">
<script
    src="/backstage/pages/governance/billing-invoices.js"
    data-empty-text="<?= I18n::e('backstage.governance.billing.invoices.empty') ?>"
    data-action-mark-paid="<?= I18n::e('backstage.governance.billing.invoices.action.mark_paid') ?>"
    data-action-waive="<?= I18n::e('backstage.governance.billing.invoices.action.waive') ?>"
    data-action-reduce="<?= I18n::e('backstage.governance.billing.invoices.action.reduce') ?>"
    data-action-audit="<?= I18n::e('backstage.governance.billing.invoices.action.audit') ?>"
    data-status-pending="<?= I18n::e('backstage.governance.billing.invoices.status.PENDING') ?>"
    data-status-paid="<?= I18n::e('backstage.governance.billing.invoices.status.PAID') ?>"
    data-status-overdue="<?= I18n::e('backstage.governance.billing.invoices.status.OVERDUE') ?>"
    data-status-waived="<?= I18n::e('backstage.governance.billing.invoices.status.WAIVED') ?>"
    data-status-reduced="<?= I18n::e('backstage.governance.billing.invoices.status.REDUCED') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
