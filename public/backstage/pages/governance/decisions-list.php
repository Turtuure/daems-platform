<?php
/**
 * Backstage Governance — Hallituksen päätökset (list)
 *
 * Filters: status dropdown + my_pending checkbox.
 * Data source: GET /api/backstage/governance/decisions[?status=...&my_pending=1]
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.decisions';
$activePage  = 'governance-decisions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.decisions')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.decisions') ?></h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/decisions/new" class="btn btn--primary">
            <?= I18n::e('backstage.governance.decisions.new') ?>
        </a>
    </div>
</div>

<section class="decisions-filters">
    <select id="filter-status" class="form-control form-control--sm">
        <option value=""><?= I18n::e('backstage.governance.decisions.filter.all_statuses') ?></option>
        <option value="pending"><?= I18n::e('backstage.governance.decisions.status.pending') ?></option>
        <option value="passed"><?= I18n::e('backstage.governance.decisions.status.passed') ?></option>
        <option value="rejected"><?= I18n::e('backstage.governance.decisions.status.rejected') ?></option>
        <option value="expired"><?= I18n::e('backstage.governance.decisions.status.expired') ?></option>
        <option value="withdrawn"><?= I18n::e('backstage.governance.decisions.status.withdrawn') ?></option>
    </select>
    <label class="decisions-filters__mine">
        <input type="checkbox" id="filter-mine">
        <?= I18n::e('backstage.governance.decisions.filter.my_pending') ?>
    </label>
</section>

<div id="decisions-list-state" hidden class="decisions-empty">
    <?= I18n::e('backstage.governance.decisions.empty') ?>
</div>

<table id="decisions-table" class="data-table decisions-table">
    <thead>
        <tr>
            <th><?= I18n::e('backstage.governance.decisions.col.type') ?></th>
            <th><?= I18n::e('backstage.governance.decisions.col.threshold') ?></th>
            <th><?= I18n::e('backstage.governance.decisions.col.tally') ?></th>
            <th><?= I18n::e('backstage.governance.decisions.col.status') ?></th>
            <th><?= I18n::e('backstage.governance.decisions.col.expires') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody id="decisions-tbody"></tbody>
</table>

<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
<script src="/backstage/pages/governance/decisions-list.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
