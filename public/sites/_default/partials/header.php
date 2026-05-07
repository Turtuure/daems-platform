<?php
declare(strict_types=1);

/**
 * Default-site header partial.
 *
 * Expects:
 *   $tenant   — \Daems\Domain\Tenant\Tenant
 *   $locale   — current locale (e.g. 'fi_FI')
 *   $pageTitle — string
 */

/** @var \Daems\Domain\Tenant\Tenant $tenant */
/** @var string $locale */
/** @var string $pageTitle */
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(substr($locale, 0, 2), ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/sites/_default/assets/default.css">
</head>
<body class="default-site">
<header class="default-header">
  <div class="default-header__inner">
    <h1 class="default-header__title">
      <a href="/"><?= htmlspecialchars($tenant->displayName($locale), ENT_QUOTES, 'UTF-8') ?></a>
    </h1>
    <nav class="default-header__locales" aria-label="Locale switcher">
      <?php foreach ($tenant->supportedLocales() as $loc): ?>
        <a href="?lang=<?= urlencode($loc) ?>"
           class="default-header__locale<?= $loc === $locale ? ' is-active' : '' ?>">
          <?= htmlspecialchars(strtoupper(substr($loc, 0, 2)), ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>
