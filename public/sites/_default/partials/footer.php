<?php
declare(strict_types=1);

/**
 * Default-site footer partial.
 */
?>
<footer class="default-footer">
  <div class="default-footer__inner">
    <small>&copy; <?= date('Y') ?></small>
    <small class="default-footer__powered">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.footer.poweredBy'), ENT_QUOTES, 'UTF-8') ?>
    </small>
  </div>
</footer>
<script src="/sites/_default/assets/default.js" defer></script>
</body>
</html>
