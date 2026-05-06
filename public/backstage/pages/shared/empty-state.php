<?php
/**
 * Backstage Empty State partial.
 *
 * Variables (set by including page before include):
 * @var string $svg_path     Required. Absolute URL path to the empty-state SVG, e.g. '/pages/backstage/insights/empty-state.svg'.
 * @var string $title        Heading.
 * @var string $body         Description.
 * @var string $cta_label    Optional CTA button label.
 * @var string $cta_id       Optional CTA button id (so per-page JS can wire onclick).
 * @var string $cta_href     Optional. If set, CTA renders as <a href>.
 */
declare(strict_types=1);

$svg_path  = (string) ($svg_path  ?? '');
$title     = (string) ($title     ?? '');
$body      = (string) ($body      ?? '');
$cta_label = (string) ($cta_label ?? '');
$cta_id    = (string) ($cta_id    ?? '');
$cta_href  = isset($cta_href) ? (string) $cta_href : null;
?>
<div class="empty-state">
  <?php if ($svg_path !== ''):
    /* Inline the SVG so page-level CSS variables (--surface-dark, --text-secondary)
       reach it; <img src> would render the SVG in its own document context where
       page tokens are unreachable, breaking dark-mode parity. */
    $svg_file = realpath(__DIR__ . '/../../..' . $svg_path);
    $public_root = realpath(__DIR__ . '/../../..');
    if ($svg_file !== false && $public_root !== false && str_starts_with($svg_file, $public_root) && is_file($svg_file)):
      $svg_markup = file_get_contents($svg_file);
      if ($svg_markup !== false):
  ?>
    <span class="empty-state__illustration" aria-hidden="true"><?= $svg_markup ?></span>
  <?php
      endif;
    endif;
  endif; ?>
  <h3 class="empty-state__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
  <p class="empty-state__body"><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($cta_label !== ''): ?>
    <?php if ($cta_href !== null): ?>
      <a class="btn btn--primary" href="<?= htmlspecialchars($cta_href, ENT_QUOTES, 'UTF-8') ?>"<?= $cta_id !== '' ? ' id="' . htmlspecialchars($cta_id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?= htmlspecialchars($cta_label, ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php else: ?>
      <button type="button" class="btn btn--primary"<?= $cta_id !== '' ? ' id="' . htmlspecialchars($cta_id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?= htmlspecialchars($cta_label, ENT_QUOTES, 'UTF-8') ?>
      </button>
    <?php endif; ?>
  <?php endif; ?>
</div>
