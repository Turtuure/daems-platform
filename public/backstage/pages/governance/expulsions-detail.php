<?php
/**
 * Backstage Governance — Erottaminen (detail)
 *
 * Route: /backstage/governance/expulsions/detail?id=<uuid>
 * Data source: GET /api/backstage/governance/expulsions/<id>
 * Actions:
 *   POST /api/backstage/governance/expulsions/<id>/statement        (target user)
 *   POST /api/backstage/governance/expulsions/<id>/advance-to-vote  (chair)
 *   POST /api/backstage/governance/expulsions/<id>/appeal           (expelled user)
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.expulsions.detail';
$activePage  = 'governance-expulsions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.expulsions'), 'url' => '/backstage/governance/expulsions'],
    ['label' => I18n::t('backstage.governance.expulsions.detail.title')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.expulsions.detail.title') ?></h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/expulsions" class="btn btn--ghost">
            &larr; <?= I18n::e('backstage.common.back') ?>
        </a>
    </div>
</div>

<div id="expulsion-not-found" hidden>
    <p class="expulsions-empty"><?= I18n::e('backstage.governance.expulsions.detail.not_found') ?></p>
</div>

<div id="expulsion-view" hidden>
    <dl class="expulsion-meta">
        <dt><?= I18n::e('backstage.governance.expulsions.col.target') ?></dt>
        <dd id="exp-target"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.detail.proposer') ?></dt>
        <dd id="exp-proposer"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.col.reason') ?></dt>
        <dd id="exp-reason"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.col.status') ?></dt>
        <dd id="exp-status"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.col.hearing_deadline') ?></dt>
        <dd id="exp-deadline"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.detail.statement_received') ?></dt>
        <dd id="exp-statement-received"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.detail.decision') ?></dt>
        <dd id="exp-decision-link"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.detail.expelled_at') ?></dt>
        <dd id="exp-expelled-at"></dd>

        <dt><?= I18n::e('backstage.governance.expulsions.detail.appeal_filed') ?></dt>
        <dd id="exp-appeal-filed"></dd>
    </dl>

    <!-- Submitted statement (read-only) -->
    <section id="statement-text-section" hidden>
        <h2 class="expulsions-section-heading"><?= I18n::e('backstage.governance.expulsions.detail.statement_heading') ?></h2>
        <pre id="statement-text" class="expulsion-text-block"></pre>
    </section>

    <!-- Statement form — shown when status=hearing, no statement yet, deadline not elapsed -->
    <section id="statement-panel" hidden class="expulsion-action-panel">
        <h2 class="expulsions-section-heading"><?= I18n::e('backstage.governance.expulsions.detail.statement_panel_heading') ?></h2>
        <form id="statement-form" class="expulsions-form">
            <label class="expulsions-form__label">
                <?= I18n::e('backstage.governance.expulsions.detail.statement_label') ?>
                <textarea name="statement_text" class="form-control" rows="6" required></textarea>
            </label>
            <div class="expulsions-form__actions">
                <button type="submit" class="btn btn--primary">
                    <?= I18n::e('backstage.governance.expulsions.detail.statement_submit') ?>
                </button>
            </div>
        </form>
    </section>

    <!-- Advance-to-vote — shown when status=hearing and (deadline elapsed OR statement received) -->
    <section id="advance-panel" hidden class="expulsion-action-panel">
        <h2 class="expulsions-section-heading"><?= I18n::e('backstage.governance.expulsions.detail.advance_heading') ?></h2>
        <form id="advance-form" class="expulsions-form">
            <label class="expulsions-form__label">
                <?= I18n::e('backstage.governance.expulsions.detail.advance_meeting_label') ?>
                <input name="meeting_reference" class="form-control" required>
            </label>
            <div class="expulsions-form__actions">
                <button type="submit" class="btn btn--primary">
                    <?= I18n::e('backstage.governance.expulsions.detail.advance_submit') ?>
                </button>
            </div>
        </form>
    </section>

    <!-- Submitted appeal (read-only) -->
    <section id="appeal-text-section" hidden>
        <h2 class="expulsions-section-heading"><?= I18n::e('backstage.governance.expulsions.detail.appeal_text_heading') ?></h2>
        <pre id="appeal-text" class="expulsion-text-block"></pre>
    </section>

    <!-- Appeal form — shown when status=expelled and no appeal yet -->
    <section id="appeal-panel" hidden class="expulsion-action-panel">
        <h2 class="expulsions-section-heading"><?= I18n::e('backstage.governance.expulsions.detail.appeal_heading') ?></h2>
        <p class="expulsions-hint"><?= I18n::e('backstage.governance.expulsions.detail.appeal_hint') ?></p>
        <form id="appeal-form" class="expulsions-form">
            <label class="expulsions-form__label">
                <?= I18n::e('backstage.governance.expulsions.detail.appeal_label') ?>
                <textarea name="appeal_text" class="form-control" rows="6" required></textarea>
            </label>
            <div class="expulsions-form__actions">
                <button type="submit" class="btn btn--secondary">
                    <?= I18n::e('backstage.governance.expulsions.detail.appeal_submit') ?>
                </button>
            </div>
        </form>
    </section>
</div>

<link rel="stylesheet" href="/backstage/pages/governance/expulsions.css">
<script src="/backstage/pages/governance/expulsions-detail.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
