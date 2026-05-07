<?php
/**
 * Backstage metric card partial — adapted from SIP's metric-card partial.
 *
 * @var string $id     Widget DOM id prefix (e.g. 'members' → #spark-members)
 * @var string $icon   SVG path(s) for the 24×24 icon viewBox
 * @var string $color  Icon color variant: blue | green | amber | purple
 * @var string $label  Card label text
 * @var int    $value  Current metric value
 * @var float  $change Week-over-week % change (positive = up, negative = down)
 * @var int    $enter  data-enter stagger index (1–4)
 */
$change ??= 0.0;
$changeDir = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral');
$changeSign = $change > 0 ? '+' : '';
?>
<div class="card card--metric" data-enter="<?= (int)$enter ?>">
    <div class="card__body">
        <div class="flex items-center gap-3 mb-3">
            <div class="metric-icon metric-icon--<?= htmlspecialchars($color, ENT_QUOTES) ?>" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <?= $icon ?>
                </svg>
            </div>
            <p class="metric-label color-secondary"><?= htmlspecialchars($label, ENT_QUOTES) ?></p>
        </div>
        <div class="flex items-end gap-3">
            <p class="metric-value" id="widget-<?= htmlspecialchars($id, ENT_QUOTES) ?>" aria-live="polite">
                <?= number_format((int)$value) ?>
            </p>
            <span class="metric-change metric-change--<?= $changeDir ?>">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <?php if ($changeDir === 'up'): ?>
                    <polyline points="18 15 12 9 6 15"/>
                    <?php elseif ($changeDir === 'down'): ?>
                    <polyline points="6 9 12 15 18 9"/>
                    <?php endif; ?>
                </svg>
                <?= $changeSign . number_format(abs((float)$change), 1) ?>%
            </span>
        </div>
        <div id="spark-<?= htmlspecialchars($id, ENT_QUOTES) ?>" class="metric-spark"></div>
    </div>
</div>
