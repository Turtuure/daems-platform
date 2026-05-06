<?php
declare(strict_types=1);

use Daems\Frontend\I18n;

$error = $_GET['error'] ?? null;
$redirect = (string) ($_GET['redirect'] ?? '/backstage');
$loc = I18n::locale();
$htmlLang = strtolower(substr($loc, 0, 2));
?>
<!doctype html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES) ?>" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= I18n::e('backstage.title.login') ?> — <?= I18n::e('backstage.layout.title_suffix') ?></title>
<link rel="stylesheet" href="/backstage/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/backstage/assets/css/daems-backstage.css">
<style>
body{display:grid;place-items:center;min-height:100vh;background:var(--surface,#f8f9fa)}
form{max-width:360px;width:100%}
</style>
</head>
<body>
<form method="post" action="/backstage/login" class="card p-4 shadow-sm">
  <h1 class="h4 mb-3"><?= I18n::e('backstage.login.heading') ?></h1>
  <?php if ($error !== null): ?>
    <div class="alert alert-danger small"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES) ?>">
  <label class="form-label"><?= I18n::e('backstage.login.email') ?></label>
  <input class="form-control mb-3" type="email" name="email" required autofocus>
  <label class="form-label"><?= I18n::e('backstage.login.password') ?></label>
  <input class="form-control mb-3" type="password" name="password" required>
  <button class="btn btn-primary w-100" type="submit"><?= I18n::e('backstage.login.submit') ?></button>
</form>
</body>
</html>
