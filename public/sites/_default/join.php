<?php
declare(strict_types=1);

/**
 * Default-site join page.
 *
 * GET  → render the join form (partials/join-form.php)
 * POST → validate fields, then attempt to forward to the existing
 *        applications API. SKELETON variant (Wave I3): performs minimal
 *        validation and shows the success screen on validation pass.
 *        Real submission to /api/v1/applications is intentionally deferred
 *        to a follow-up wave so the skeleton compiles without a hard
 *        dependency on a specific module use-case shape.
 */

$tenant = $GLOBALS['_default_site_tenant'] ?? null;
$locale = $GLOBALS['_default_site_locale'] ?? 'en_GB';
if (!$tenant instanceof \Daems\Domain\Tenant\Tenant) {
    http_response_code(500);
    exit('Tenant context missing');
}

$pageTitle = sprintf(
    \Daems\Frontend\I18n::t('default.join.title'),
    $tenant->displayName($locale),
);

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim((string) ($_POST['name']  ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $dob   = trim((string) ($_POST['dob']   ?? ''));

    if ($name === '') {
        $errors['name'] = \Daems\Frontend\I18n::t('default.join.error.required');
    }
    if ($email === '') {
        $errors['email'] = \Daems\Frontend\I18n::t('default.join.error.required');
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = \Daems\Frontend\I18n::t('default.join.error.invalid_email');
    }
    if ($dob === '') {
        $errors['dob'] = \Daems\Frontend\I18n::t('default.join.error.required');
    } elseif (\DateTimeImmutable::createFromFormat('Y-m-d', $dob) === false) {
        $errors['dob'] = \Daems\Frontend\I18n::t('default.join.error.invalid_dob');
    }

    if ($errors === []) {
        // SKELETON: real submission to the applications API is deferred.
        // Container is available as $GLOBALS['_default_site_container'] for
        // the eventual wiring — see public/sites-router.php.
        $success = true;
    }
}

require __DIR__ . '/partials/header.php';
?>
<main class="default-join">
  <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h2>
  <?php if ($success): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars(\Daems\Frontend\I18n::t('default.join.success'), ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php else: ?>
    <p><?= htmlspecialchars(\Daems\Frontend\I18n::t('default.join.intro'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($errors !== []): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $field => $msg): ?>
          <div><strong><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?>:</strong>
            <?= htmlspecialchars((string) $msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php require __DIR__ . '/partials/join-form.php'; ?>
  <?php endif; ?>
</main>
<?php
require __DIR__ . '/partials/footer.php';
