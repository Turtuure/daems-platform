<?php
/**
 * Backstage Governance — Erottamiset (list)
 *
 * Filters: status dropdown.
 * Data source: GET /api/backstage/governance/expulsions[?status=...]
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.expulsions';
$activePage  = 'governance-expulsions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.expulsions')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.expulsions') ?></h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/expulsions/new" class="btn btn--primary">
            <?= I18n::e('backstage.governance.expulsions.action.initiate') ?>
        </a>
    </div>
</div>

<section class="expulsions-filters">
    <select id="filter-status" class="form-control form-control--sm">
        <option value=""><?= I18n::e('backstage.governance.expulsions.filter.all_statuses') ?></option>
        <option value="hearing"><?= I18n::e('backstage.governance.expulsions.status.hearing') ?></option>
        <option value="awaiting_vote"><?= I18n::e('backstage.governance.expulsions.status.awaiting_vote') ?></option>
        <option value="expelled"><?= I18n::e('backstage.governance.expulsions.status.expelled') ?></option>
        <option value="rejected"><?= I18n::e('backstage.governance.expulsions.status.rejected') ?></option>
        <option value="appealed"><?= I18n::e('backstage.governance.expulsions.status.appealed') ?></option>
    </select>
</section>

<div id="expulsions-empty" hidden class="expulsions-empty">
    <?= I18n::e('backstage.governance.expulsions.empty') ?>
</div>

<table id="expulsions-table" class="data-table expulsions-table">
    <thead>
        <tr>
            <th><?= I18n::e('backstage.governance.expulsions.col.target') ?></th>
            <th><?= I18n::e('backstage.governance.expulsions.col.reason') ?></th>
            <th><?= I18n::e('backstage.governance.expulsions.col.status') ?></th>
            <th><?= I18n::e('backstage.governance.expulsions.col.hearing_deadline') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody id="expulsions-tbody"></tbody>
</table>

<link rel="stylesheet" href="/backstage/pages/governance/expulsions.css">
<script src="/backstage/pages/governance/expulsions-list.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
