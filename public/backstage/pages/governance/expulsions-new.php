<?php
/**
 * Backstage Governance — Aloita erottaminen (new)
 *
 * Route: /backstage/governance/expulsions/new
 * On submit: POST /api/backstage/governance/expulsions
 * On success: redirect to /backstage/governance/expulsions/detail?id=<uuid>
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.governance.expulsions.new';
$activePage  = 'governance-expulsions';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.governance')],
    ['label' => I18n::t('shell.governance.expulsions'), 'url' => '/backstage/governance/expulsions'],
    ['label' => I18n::t('backstage.governance.expulsions.action.initiate')],
];

// Pre-fill target_user_id from ?target_user_id= query param (set by members page row-action).
$prefilledTargetUserId = trim((string) ($_GET['target_user_id'] ?? ''));

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.governance.expulsions.action.initiate') ?></h1>
    </div>
    <div class="page-header__actions">
        <a href="/backstage/governance/expulsions" class="btn btn--ghost">
            &larr; <?= I18n::e('backstage.common.back') ?>
        </a>
    </div>
</div>

<div class="expulsions-new-wrap">
    <form id="initiate-form" class="expulsions-form">
        <label class="expulsions-form__label">
            <?= I18n::e('backstage.governance.expulsions.new.target_label') ?>
            <input name="target_user_id" class="form-control" required
                   value="<?= htmlspecialchars($prefilledTargetUserId, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="<?= I18n::e('backstage.governance.expulsions.new.target_placeholder') ?>">
        </label>

        <label class="expulsions-form__label">
            <?= I18n::e('backstage.governance.expulsions.new.reason_label') ?>
            <textarea name="reason" class="form-control" rows="5" required></textarea>
        </label>

        <p class="expulsions-hint"><?= I18n::e('backstage.governance.expulsions.new.deadline_hint') ?></p>

        <div class="expulsions-form__actions">
            <button type="submit" class="btn btn--primary">
                <?= I18n::e('backstage.governance.expulsions.new.submit') ?>
            </button>
            <a href="/backstage/governance/expulsions" class="btn btn--ghost">
                <?= I18n::e('backstage.common.cancel') ?>
            </a>
        </div>
    </form>
</div>

<link rel="stylesheet" href="/backstage/pages/governance/expulsions.css">
<script src="/backstage/pages/governance/expulsions-new.js" defer></script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
