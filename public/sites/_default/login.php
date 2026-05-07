<?php
declare(strict_types=1);

/**
 * Default-site login redirect.
 *
 * The default site does not host its own login form — it bounces visitors
 * to the backstage login page, which is the canonical sign-in surface for
 * any tenant served by the default site.
 */

header('Location: /backstage/login', true, 302);
exit;
