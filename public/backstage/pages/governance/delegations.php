<?php
/**
 * Backstage Governance — Delegoinnit (list)
 *
 * Shows active delegations granted by the board.
 * Data source: GET /api/backstage/governance/delegations
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle  = 'backstage.title.governance.delegations';
$activePage = 'governance-delegations';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.delegations')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.delegations') ?></h1>
    </div>
</div>

<p class="hint"><?= I18n::e('backstage.governance.delegations.hint') ?></p>

<div id="delegations-empty" hidden class="decisions-empty">
    <?= I18n::e('backstage.governance.delegations.empty') ?>
</div>

<table id="delegations-table" class="data-table delegations-table">
    <thead>
        <tr>
            <th><?= I18n::e('backstage.governance.delegations.col.decision_type') ?></th>
            <th><?= I18n::e('backstage.governance.delegations.col.delegated_to') ?></th>
            <th><?= I18n::e('backstage.governance.delegations.col.valid_from') ?></th>
            <th><?= I18n::e('backstage.governance.delegations.col.source_decision') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody id="delegations-tbody"></tbody>
</table>

<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
<script src="/backstage/pages/governance/delegations.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
