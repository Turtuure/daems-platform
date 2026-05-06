<?php
/**
 * Sub-page card — small clickable shortcut from a primary admin page
 * to a related sub-page (e.g. Events → Event Proposals).
 *
 * Inputs (set as variables in the host scope before include):
 *   string  $cardTitle        Display label.
 *   string  $cardHref         Link target (absolute path).
 *   int     $cardPendingCount Pending item count; 0 hides the badge.
 *   ?string $cardSubtitle     Optional one-liner under title.
 */
declare(strict_types=1);

$__title    = isset($cardTitle) && is_string($cardTitle) ? $cardTitle : '';
$__href     = isset($cardHref) && is_string($cardHref) ? $cardHref : '#';
$__pending  = isset($cardPendingCount) && is_int($cardPendingCount) && $cardPendingCount > 0
    ? $cardPendingCount
    : 0;
$__subtitle = isset($cardSubtitle) && is_string($cardSubtitle) && $cardSubtitle !== ''
    ? $cardSubtitle
    : null;
?>
<a class="sub-page-card" href="<?= htmlspecialchars($__href, ENT_QUOTES, 'UTF-8') ?>">
    <span class="sub-page-card__body">
        <span class="sub-page-card__title"><?= htmlspecialchars($__title, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($__subtitle !== null): ?>
            <span class="sub-page-card__subtitle"><?= htmlspecialchars($__subtitle, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </span>
    <?php if ($__pending > 0): ?>
        <span class="sub-page-card__badge" title="Avoinna"><?= $__pending ?></span>
    <?php endif; ?>
    <span class="sub-page-card__arrow" aria-hidden="true">&rarr;</span>
</a>
