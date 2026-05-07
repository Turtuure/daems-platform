<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

/**
 * The runtime state of a module for a specific tenant.
 *
 * - ENABLED:                tenant has the module turned on (and it was made available)
 * - AVAILABLE_NOT_ENABLED:  platform admin has granted availability, tenant admin
 *                           has not yet enabled it
 * - DISABLED:               module is not available to this tenant (or row was revoked)
 * - CORE:                   module is bundled core; always-on regardless of DB rows
 */
enum ModuleState: string
{
    case ENABLED = 'enabled';
    case AVAILABLE_NOT_ENABLED = 'available';
    case DISABLED = 'disabled';
    case CORE = 'core';

    /**
     * Returns true when the module is functionally on for this tenant —
     * i.e. ENABLED by tenant admin, or CORE (always on). This is the
     * predicate the route guard and sidebar use.
     */
    public function isActive(): bool
    {
        return $this === self::ENABLED || $this === self::CORE;
    }
}
