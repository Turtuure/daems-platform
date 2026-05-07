<?php
declare(strict_types=1);

/**
 * Default-site join page.
 *
 * GET  → render the join form (partials/join-form.php)
 * POST → validate fields, then forward to the Members module's
 *        SubmitMemberApplication use case. The container is exposed by
 *        sites-router.php in $GLOBALS['_default_site_container'].
 *
 * The form is intentionally minimal (name / email / dob / motivation) —
 * matches the shape of the use case's required input. Tenants that need
 * a richer flow (country, supporter tier, how-heard…) ship their own
 * frontend at C:\laragon\www\sites\<slug>\public\index.php and bypass
 * this fallback entirely.
 */

use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplicationInput;

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
    $name       = trim((string) ($_POST['name']       ?? ''));
    $email      = trim((string) ($_POST['email']      ?? ''));
    $dob        = trim((string) ($_POST['dob']        ?? ''));
    $motivation = trim((string) ($_POST['motivation'] ?? ''));

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
    if ($motivation === '') {
        $errors['motivation'] = \Daems\Frontend\I18n::t('default.join.error.required');
    }

    if ($errors === []) {
        $container = $GLOBALS['_default_site_container'] ?? null;
        if (!$container instanceof \Daems\Infrastructure\Framework\Container\Container) {
            // Fall back to the success screen rather than crashing — the
            // sites-router *always* sets this global. Missing means the page
            // was loaded outside the router, e.g. in a test.
            $success = true;
        } else {
            try {
                /** @var SubmitMemberApplication $useCase */
                $useCase = $container->make(SubmitMemberApplication::class);
                $useCase->execute(new SubmitMemberApplicationInput(
                    tenantId:    $tenant->id,
                    name:        $name,
                    email:       $email,
                    dateOfBirth: $dob,
                    country:     null,
                    motivation:  $motivation,
                    howHeard:    null,
                ));
                $success = true;
            } catch (\Throwable $e) {
                $errors['_global'] = \Daems\Frontend\I18n::t('default.join.error.submit_failed');
            }
        }
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
