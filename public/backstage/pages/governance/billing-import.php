<?php
/**
 * Backstage Governance — CSV import (Nordea bank statement).
 *
 * Two-step flow:
 *   1. Upload CSV → POST /api/backstage/governance/billing/payments/import-csv (multipart)
 *      Backend parses + matches; returns preview JSON.
 *   2. User ticks matches → POST /api/backstage/governance/billing/payments/import-csv/confirm
 *      Backend dispatches RecordManualPayment per match with method='csv_import'.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.billing.import';
$activePage  = 'governance-billing';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.billing'), 'href' => '/backstage/governance/billing'],
    ['label' => I18n::t('backstage.governance.billing.import.link')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.billing.import.heading') ?></h1>
        <p class="page-header__subtitle"><?= I18n::e('backstage.governance.billing.import.description') ?></p>
    </div>
</div>

<section class="billing-import">
    <form id="import-form" enctype="multipart/form-data" class="billing-import__form">
        <label><?= I18n::e('backstage.governance.billing.import.file_label') ?>
            <input type="file" name="csv" accept=".csv,text/csv" required>
        </label>
        <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.import.preview_button') ?></button>
    </form>

    <section id="preview" hidden>
        <h3><?= I18n::e('backstage.governance.billing.import.preview_heading') ?></h3>
        <p><strong id="high-confidence-count">0</strong> <?= I18n::e('backstage.governance.billing.import.high_confidence_count') ?></p>
        <table class="billing-import__preview">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.row') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.payer') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.reference') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.amount') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.match') ?></th>
                    <th><?= I18n::e('backstage.governance.billing.import.col.confidence') ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <button type="button" id="confirm-btn" class="btn btn--primary"><?= I18n::e('backstage.governance.billing.import.confirm_button') ?></button>
    </section>

    <section id="results" hidden>
        <h3><?= I18n::e('backstage.governance.billing.import.results_heading') ?></h3>
        <p>
            <strong id="applied-count">0</strong> <?= I18n::e('backstage.governance.billing.import.applied_label') ?>
            <strong id="error-count">0</strong> <?= I18n::e('backstage.governance.billing.import.error_label') ?>
        </p>
        <details>
            <summary><?= I18n::e('backstage.governance.billing.import.details') ?></summary>
            <pre id="results-details"></pre>
        </details>
    </section>
</section>

<link rel="stylesheet" href="/backstage/pages/governance/billing-import.css">
<script
    src="/backstage/pages/governance/billing-import.js"
    data-confidence-high="<?= I18n::e('backstage.governance.billing.import.confidence.high') ?>"
    data-confidence-amount-mismatch="<?= I18n::e('backstage.governance.billing.import.confidence.amount_mismatch') ?>"
    data-confidence-no-match="<?= I18n::e('backstage.governance.billing.import.confidence.no_match') ?>"
    data-alert-select="<?= I18n::e('backstage.governance.billing.import.alert.select_at_least_one') ?>"
    data-alert-preview="<?= I18n::e('backstage.governance.billing.import.alert.preview_failed') ?>"
    data-alert-confirm="<?= I18n::e('backstage.governance.billing.import.alert.confirm_failed') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
