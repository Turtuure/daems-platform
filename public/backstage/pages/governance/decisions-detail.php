<?php
/**
 * Backstage Governance — Päätöksen tiedot (detail)
 *
 * Route: /backstage/governance/decisions/detail?id=<uuid>
 * Data source: GET /api/backstage/governance/decisions/<id>
 * Actions: POST /api/backstage/governance/decisions/<id>/vote
 *          POST /api/backstage/governance/decisions/<id>/withdraw
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.decisions.detail';
$activePage  = 'governance-decisions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.decisions'), 'url' => '/backstage/governance/decisions'],
    ['label' => I18n::t('backstage.governance.decisions.detail.title')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title" id="decision-heading">
            <?= I18n::e('backstage.governance.decisions.detail.title') ?>
        </h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/decisions" class="btn btn--ghost">
            &larr; <?= I18n::e('backstage.common.back') ?>
        </a>
    </div>
</div>

<div id="decision-not-found" hidden>
    <p class="decisions-empty"><?= I18n::e('backstage.governance.decisions.detail.not_found') ?></p>
</div>

<div id="decision-view" hidden>
    <dl class="decision-meta">
        <dt><?= I18n::e('backstage.governance.decisions.col.type') ?></dt>
        <dd id="decision-type"></dd>

        <dt><?= I18n::e('backstage.governance.decisions.col.status') ?></dt>
        <dd id="decision-status"></dd>

        <dt><?= I18n::e('backstage.governance.decisions.detail.threshold_mode') ?></dt>
        <dd id="decision-th-mode"></dd>

        <dt><?= I18n::e('backstage.governance.decisions.col.expires') ?></dt>
        <dd id="decision-expires"></dd>

        <dt><?= I18n::e('backstage.governance.decisions.detail.meeting_reference') ?></dt>
        <dd id="decision-meeting"></dd>

        <dt><?= I18n::e('backstage.governance.decisions.detail.via_delegation') ?></dt>
        <dd id="decision-via-delegation"></dd>
    </dl>

    <h2 class="decisions-section-heading"><?= I18n::e('backstage.governance.decisions.detail.payload') ?></h2>
    <pre id="decision-payload" class="decision-payload"></pre>

    <h2 class="decisions-section-heading"><?= I18n::e('backstage.governance.decisions.detail.votes') ?></h2>
    <div id="decision-tally" class="decision-tally-summary"></div>
    <ul id="decision-votes" class="decision-votes-list"></ul>

    <!-- Vote panel — visible only when status=pending -->
    <section id="vote-panel" hidden class="decision-action-panel">
        <h2 class="decisions-section-heading"><?= I18n::e('backstage.governance.decisions.detail.vote_heading') ?></h2>
        <form id="vote-form" class="decisions-form decisions-form--inline">
            <label class="decisions-form__radio">
                <input type="radio" name="vote" value="yes" required>
                <?= I18n::e('backstage.governance.decisions.vote.yes') ?>
            </label>
            <label class="decisions-form__radio">
                <input type="radio" name="vote" value="no">
                <?= I18n::e('backstage.governance.decisions.vote.no') ?>
            </label>
            <label class="decisions-form__radio">
                <input type="radio" name="vote" value="abstain">
                <?= I18n::e('backstage.governance.decisions.vote.abstain') ?>
            </label>
            <button type="submit" class="btn btn--primary">
                <?= I18n::e('backstage.governance.decisions.detail.vote_submit') ?>
            </button>
        </form>
    </section>

    <!-- Withdraw panel — visible only when status=pending and user is proposer -->
    <section id="withdraw-panel" hidden class="decision-action-panel">
        <h2 class="decisions-section-heading"><?= I18n::e('backstage.governance.decisions.detail.withdraw_heading') ?></h2>
        <form id="withdraw-form" class="decisions-form">
            <label class="decisions-form__label">
                <?= I18n::e('backstage.governance.decisions.detail.withdraw_reason_label') ?>
                <textarea name="withdrawal_reason" class="form-control" rows="2" required></textarea>
            </label>
            <div class="decisions-form__actions">
                <button type="submit" class="btn btn--secondary">
                    <?= I18n::e('backstage.governance.decisions.detail.withdraw_submit') ?>
                </button>
            </div>
        </form>
    </section>
</div>

<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
<script src="/backstage/pages/governance/decisions-detail.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
