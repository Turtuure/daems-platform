<?php

declare(strict_types=1);

/**
 * Wave G2/G3 — module route guard + container access for the backstage
 * front-controller.
 *
 * Loads the bootstrap container once and exposes it (plus the resolved
 * Tenant entity for the current host) in $GLOBALS so downstream templates
 * — notably layout.php — can pull the same services without re-bootstrapping.
 *
 * Returns: array{0: \Daems\Domain\Tenant\ModuleRouteGuard, 1: ?\Daems\Domain\Tenant\TenantId}
 *
 * Side-effects (after first include only):
 *   $GLOBALS['daems_backstage_container'] = Container
 *   $GLOBALS['daems_backstage_tenant']    = ?Tenant
 *
 * Idempotent — safe to require multiple times. Subsequent includes return
 * the cached pair without re-running bootstrap/app.php.
 *
 * Why a separate helper: the backstage front-controller (public/backstage.php)
 * is a separate process from the API kernel (public/index.php). It does NOT
 * load the bootstrap container by default. Bringing it in here keeps the
 * side-effects local to backstage requests that actually need the guard.
 * All bindings inside bootstrap/app.php are lazy so this is cheap (no DB
 * connection until the first query fires).
 *
 * Why pre-auth: a tenant whose forum is disabled should see the same 404
 * as a tenant whose forum doesn't exist — running the guard before the
 * auth check guarantees that, since an unauthenticated visitor cannot
 * distinguish disabled from missing.
 */

if (!isset($GLOBALS['daems_backstage_module_guard'])) {
    require_once __DIR__ . '/../../vendor/autoload.php';

    /** @var \Daems\Infrastructure\Framework\Http\Kernel $__kernel */
    $__kernel = require __DIR__ . '/../../bootstrap/app.php';
    unset($__kernel); // we only need the side-effect — $container is now in this scope

    if (!isset($container) || !$container instanceof \Daems\Infrastructure\Framework\Container\Container) {
        throw new \RuntimeException('bootstrap/app.php did not expose $container');
    }

    /** @var \Daems\Domain\Tenant\ModuleRouteGuard $__guard */
    $__guard = $container->make(\Daems\Domain\Tenant\ModuleRouteGuard::class);

    /** @var \Daems\Infrastructure\Tenant\HostTenantResolver $__resolver */
    $__resolver = $container->make(\Daems\Infrastructure\Tenant\HostTenantResolver::class);

    $__host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $__tenant = $__resolver->resolve($__host);

    // Pin the tenant's default locale so I18n::locale() can fall back to it
    // for anonymous visitors with no Accept-Language / cookie / session
    // signal. Defensive guard: only set if the tenant's defaultLocale is in
    // its own supportedLocales list.
    if ($__tenant !== null) {
        $__tenantSupported = $__tenant->supportedLocales();
        $__tenantDefault   = $__tenant->defaultLocale();
        if (in_array($__tenantDefault, $__tenantSupported, true)) {
            \Daems\Frontend\I18n::setTenantDefault($__tenantDefault);
        }
    }

    $GLOBALS['daems_backstage_container']    = $container;
    $GLOBALS['daems_backstage_tenant']       = $__tenant;
    $GLOBALS['daems_backstage_module_guard'] = $__guard;
    $GLOBALS['daems_backstage_tenant_id']    = $__tenant !== null ? $__tenant->id : null;
}

return [
    $GLOBALS['daems_backstage_module_guard'],
    $GLOBALS['daems_backstage_tenant_id'],
];
