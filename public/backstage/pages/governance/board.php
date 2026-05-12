<?php
/**
 * Backstage Governance — Hallitus (Board)
 *
 * Two states:
 *   1. Board exists  → roster cards with role, term dates, days remaining.
 *   2. Not bootstrapped → banner + "Istuta hallitus" modal (GSA-only).
 *
 * Data source: GET /api/backstage/governance/board
 * Bootstrap:   POST /api/backstage/governance/board/bootstrap
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.board';
$activePage  = 'governance-board';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.board')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('shell.governance.board') ?></h1>
    </div>
</div>

<section id="board-roster" hidden>
    <div class="board-cards" id="board-cards"></div>
</section>

<section id="board-bootstrap" hidden>
    <div class="bootstrap-banner">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true" class="bootstrap-banner__icon">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <h2><?= I18n::e('backstage.governance.board.not_bootstrapped.heading') ?></h2>
        <p><?= I18n::e('backstage.governance.board.not_bootstrapped.hint') ?></p>
        <button id="open-bootstrap" class="btn btn--primary">
            <?= I18n::e('backstage.governance.board.bootstrap.open') ?>
        </button>
    </div>

    <dialog id="bootstrap-modal" class="daems-dialog">
        <form id="bootstrap-form" method="dialog">
            <div class="daems-dialog__header">
                <h3 class="daems-dialog__title"><?= I18n::e('backstage.governance.board.bootstrap.modal_title') ?></h3>
            </div>
            <div class="daems-dialog__body">
                <div id="bootstrap-rows"></div>
                <button type="button" id="add-row" class="btn btn--ghost btn--sm">
                    + <?= I18n::e('backstage.governance.board.bootstrap.add_row') ?>
                </button>
            </div>
            <div class="daems-dialog__footer">
                <button type="button" id="cancel-bootstrap" class="btn btn--ghost">
                    <?= I18n::e('backstage.common.cancel') ?>
                </button>
                <button type="submit" class="btn btn--primary">
                    <?= I18n::e('backstage.governance.board.bootstrap.submit') ?>
                </button>
            </div>
        </form>
    </dialog>
</section>

<link rel="stylesheet" href="/backstage/pages/governance/board.css">
<script src="/backstage/pages/governance/board.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
