<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Decides whether a given URL path is reachable for a given tenant, based
 * on which module owns the path (registry's longest-prefix match) and
 * whether that module is currently active for the tenant.
 *
 * Mounted ahead of the router so disabled modules return 404 BEFORE auth —
 * a tenant should not even know whether a forum exists if their forum is
 * disabled.
 *
 * Returns ALLOW for paths no module claims (shell routes, login, the
 * homepage etc.) — those are gated by the router and middleware downstream.
 */
final class ModuleRouteGuard
{
    public const ALLOW = 'allow';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModuleResolver $resolver,
    ) {}

    /**
     * @return self::ALLOW|self::NOT_FOUND
     */
    public function authorize(TenantId $tenantId, string $path): string
    {
        $owner = $this->registry->findOwnerOfPath($path);
        if ($owner === null) {
            return self::ALLOW;
        }
        $state = $this->resolver->stateFor($tenantId, $owner->name());
        return $state->isActive() ? self::ALLOW : self::NOT_FOUND;
    }
}
