<?php
/**
 * Backstage Governance — Uusi hallituksen päätös (wizard)
 *
 * Step 1: choose decision_type.
 * Step 2: type-specific fields rendered by JS.
 * On submit: POST to /api/backstage/governance/decisions/<type>
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.decisions.new';
$activePage  = 'governance-decisions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.decisions'), 'url' => '/backstage/governance/decisions'],
    ['label' => I18n::t('backstage.governance.decisions.new')],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.decisions.new') ?></h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/decisions" class="btn btn--ghost">
            &larr; <?= I18n::e('backstage.common.back') ?>
        </a>
    </div>
</div>

<div class="decisions-new-wrap">
    <form id="propose-form" class="decisions-form">
        <label class="decisions-form__label">
            <?= I18n::e('backstage.governance.decisions.new.type_label') ?>
            <select id="decision-type" name="decision_type" class="form-control">
                <option value="approve_basic">approve_basic — Hyväksy perusjäseneksi</option>
                <option value="invite_full">invite_full — Kutsu varsinaiseksi jäseneksi</option>
                <option value="award_subtier">award_subtier — Myönnä alatason kunnia</option>
                <option value="revoke_subtier">revoke_subtier — Peru alatason kunnia</option>
                <option value="subtier_crud">subtier_crud — Muokkaa kunniaportaikkoa</option>
                <option value="remove_board_member">remove_board_member — Poista hallituksesta</option>
                <option value="delegate_authority">delegate_authority — Delegoi päätösvalta</option>
                <option value="revoke_delegation">revoke_delegation — Peru delegointi</option>
            </select>
        </label>

        <!-- Type-specific fields injected by JS -->
        <fieldset id="type-fields" class="decisions-form__type-fields">
            <legend>Päätöstiedot</legend>
            <!-- filled by decisions-new.js -->
        </fieldset>

        <label class="decisions-form__label">
            <?= I18n::e('backstage.governance.decisions.new.vote_visibility_label') ?>
            <select name="vote_visibility" class="form-control">
                <option value="visible">Näkyvät</option>
                <option value="anonymous">Anonyymi</option>
            </select>
        </label>

        <label id="meeting-ref-wrap" class="decisions-form__label">
            <?= I18n::e('backstage.governance.decisions.new.meeting_reference_label') ?>
            <input name="meeting_reference" class="form-control"
                   placeholder="esim. Hallituksen kokous 2026-05-20, PK-12">
        </label>

        <div class="decisions-form__actions">
            <button type="submit" class="btn btn--primary">
                <?= I18n::e('backstage.governance.decisions.new.submit') ?>
            </button>
            <a href="/backstage/governance/decisions" class="btn btn--ghost">
                <?= I18n::e('backstage.common.cancel') ?>
            </a>
        </div>
    </form>
</div>

<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
<script src="/backstage/pages/governance/decisions-new.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
