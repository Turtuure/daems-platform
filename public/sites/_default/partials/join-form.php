<?php
declare(strict_types=1);

/**
 * Default-site join-form partial.
 *
 * Form-fields-only — no <main>/<header>/<footer>. Expects to be included
 * by join.php after the page chrome has been emitted.
 */
?>
<form method="post" action="/join" class="default-join-form">
  <div class="form-row">
    <label for="join-name">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.join.field.name'), ENT_QUOTES, 'UTF-8') ?>
    </label>
    <input type="text" id="join-name" name="name" required>
  </div>
  <div class="form-row">
    <label for="join-email">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.join.field.email'), ENT_QUOTES, 'UTF-8') ?>
    </label>
    <input type="email" id="join-email" name="email" required>
  </div>
  <div class="form-row">
    <label for="join-dob">DOB</label>
    <input type="date" id="join-dob" name="dob" required>
  </div>
  <button type="submit" class="cta cta-primary">
    <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.join.submit'), ENT_QUOTES, 'UTF-8') ?>
  </button>
</form>
