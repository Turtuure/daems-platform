<?php
declare(strict_types=1);

/**
 * Default-site front controller (Wave I4).
 *
 * Decides per-request whether to delegate to a tenant's *custom* frontend
 * (a separate Laragon site at C:\laragon\www\sites\<slug>\public\index.php)
 * or fall back to the *default* public site bundled at
 * public/sites/_default/.
 *
 * Decision tree:
 *   1. Resolve tenant from Host header.
 *   2. If unknown                 → 404 "Unknown tenant".
 *   3. If suspended()             → public/sites/_default/suspended.php (HTTP 503).
 *   4. Else if custom-site exists → require that index.php (delegate).
 *   5. Else (no custom site)      → route to public/sites/_default/{index|join|login}.php.
 *
 * Invoked from public/index.php for any non-API, non-backstage,
 * non-static-asset request — see the integration block at the top of
 * public/index.php.
 */

/** @var \Daems\Infrastructure\Framework\Http\Kernel $kernel */
$kernel = $GLOBALS['_daems_kernel'] ?? null;
if (!$kernel instanceof \Daems\Infrastructure\Framework\Http\Kernel) {
    // Standalone invocation — boot the kernel ourselves.
    require_once __DIR__ . '/../vendor/autoload.php';
    $kernel = require __DIR__ . '/../bootstrap/app.php';
    if (!$kernel instanceof \Daems\Infrastructure\Framework\Http\Kernel) {
        http_response_code(500);
        exit('Failed to bootstrap kernel');
    }
}

$container = $kernel->container();

/** @var \Daems\Infrastructure\Tenant\TenantResolverInterface $tenantResolver */
$tenantResolver = $container->make(\Daems\Infrastructure\Tenant\TenantResolverInterface::class);
$host           = (string) ($_SERVER['HTTP_HOST'] ?? '');
$tenant         = $tenantResolver->resolve($host);

if (!$tenant instanceof \Daems\Domain\Tenant\Tenant) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><h1>404 — Unknown tenant</h1>'
       . '<p>No tenant is configured for host <code>'
       . htmlspecialchars($host, ENT_QUOTES, 'UTF-8')
       . '</code>.</p></body></html>';
    exit;
}

/* ------------------------------------------------------------------ */
/* Locale negotiation                                                  */
/* ------------------------------------------------------------------ */

// Pin the tenant's preferred locale BEFORE I18n::locale() runs so anonymous
// visitors with no Accept-Language / cookie / session fall back to the
// tenant's default rather than the platform-wide en_GB. Constrained to the
// tenant's own supported set as a defensive guard against misconfigured
// tenants_default_locale rows.
$tenantSupported = $tenant->supportedLocales();
$tenantDefault   = $tenant->defaultLocale();
if (in_array($tenantDefault, $tenantSupported, true)) {
    \Daems\Frontend\I18n::setTenantDefault($tenantDefault);
}

// Use the same resolver the rest of the platform uses; constrain to the
// tenant's supportedLocales() and fall back to its defaultLocale().
$locale = \Daems\Frontend\I18n::locale();
$supported = $tenant->supportedLocales();
if (!in_array($locale, $supported, true)) {
    $locale = $tenant->defaultLocale();
}

// Layer the default-site lang map onto the platform-wide I18n dictionary.
// Existing platform keys win — these only add the page-specific
// `default.*` keys.
$siteLangFile = __DIR__ . '/sites/_default/lang/' . $locale . '.php';
if (is_file($siteLangFile)) {
    /** @var mixed $extra */
    $extra = require $siteLangFile;
    if (is_array($extra)) {
        /** @var array<string, string> $extra */
        \Daems\Frontend\I18n::merge($locale, $extra);
    }
}

/* ------------------------------------------------------------------ */
/* Suspended → 503                                                     */
/* ------------------------------------------------------------------ */

if ($tenant->suspended()) {
    $GLOBALS['_default_site_tenant'] = $tenant;
    $GLOBALS['_default_site_locale'] = $locale;
    require __DIR__ . '/sites/_default/suspended.php';
    exit;
}

/* ------------------------------------------------------------------ */
/* Custom-site delegation                                              */
/* ------------------------------------------------------------------ */
//
// If the tenant ships its own frontend at C:\laragon\www\sites\<slug>\public\,
// hand off control entirely. The custom site is responsible for everything
// from there (its own router, session, etc.). NO further default-site
// processing happens for this request.

$customSitePath = realpath(__DIR__ . '/../../sites/' . $tenant->slug->value() . '/public/index.php');
if ($customSitePath !== false && is_file($customSitePath)) {
    require $customSitePath;
    exit;
}

/* ------------------------------------------------------------------ */
/* Default-site routing                                                */
/* ------------------------------------------------------------------ */

$GLOBALS['_default_site_tenant']    = $tenant;
$GLOBALS['_default_site_locale']    = $locale;
$GLOBALS['_default_site_container'] = $container;

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

// Static-asset passthrough for /sites/_default/assets/*. Apache typically
// serves these directly, but the built-in PHP server (and some Apache
// configs) hit this controller first.
if (str_starts_with($path, '/sites/_default/assets/')) {
    $rel  = substr($path, strlen('/sites/_default/'));
    $base = realpath(__DIR__ . '/sites/_default');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

switch ($path) {
    case '/':
    case '':
        require __DIR__ . '/sites/_default/index.php';
        break;
    case '/join':
        require __DIR__ . '/sites/_default/join.php';
        break;
    case '/login':
        require __DIR__ . '/sites/_default/login.php';
        break;
    default:
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body><h1>404</h1></body></html>';
}
