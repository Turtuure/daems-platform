<?php

declare(strict_types=1);

/**
 * Wave G2 — module route guard for the backstage front-controller.
 *
 * Returns the (ModuleRouteGuard, TenantId|null) pair so the surrounding
 * router can short-circuit /backstage/<module>/* and /api/backstage/<module>
 * URLs to 404 BEFORE the auth check when the owning module is disabled
 * for the current tenant. Doing it pre-auth means an unauthenticated
 * visitor sees the same 404 whether the module is disabled or doesn't
 * exist — a tenant should not even know the forum exists if their forum
 * is off.
 *
 * The backstage front-controller (public/backstage.php) is a separate
 * process from the API kernel (public/index.php). It does NOT load the
 * full bootstrap container by default — bringing it in here keeps the
 * binding side-effects local to backstage requests that actually need
 * the guard. All bindings inside bootstrap/app.php are lazy so this is
 * cheap (no DB connection until a query fires).
 *
 * Returns: array{0: \Daems\Domain\Tenant\ModuleRouteGuard, 1: ?\Daems\Domain\Tenant\TenantId}
 */

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
$__tenantId = $__tenant !== null ? $__tenant->id : null;

return [$__guard, $__tenantId];
