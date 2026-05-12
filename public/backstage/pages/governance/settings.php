<?php
/**
 * Backstage Governance — Hallinnon asetukset (read-only)
 *
 * Shows default governance settings. Full edit UI deferred to 0.6c
 * (endpoint missing — no PUT /api/backstage/governance/settings yet).
 * GSA can edit values directly in tenant_governance_settings table.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle  = 'backstage.title.governance.settings';
$activePage = 'governance-settings';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.settings')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.settings') ?></h1>
    </div>
</div>

<p class="hint"><?= I18n::e('backstage.governance.settings.hint') ?></p>

<dl class="settings-meta">
    <dt><?= I18n::e('backstage.governance.settings.expulsion_hearing_days') ?></dt>
    <dd>14 vrk</dd>

    <dt><?= I18n::e('backstage.governance.settings.decision_expiration_days') ?></dt>
    <dd>60 vrk</dd>
</dl>

<p class="hint"><?= I18n::e('backstage.governance.settings.db_note') ?></p>

<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
