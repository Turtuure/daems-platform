<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

/**
 * The four audit-loggable actions on a tenant_module row.
 *
 * MADE_AVAILABLE / REVOKED_AVAILABILITY are platform-admin actions.
 * ENABLED / DISABLED are tenant-admin actions.
 */
enum ModuleAuditAction: string
{
    case MADE_AVAILABLE = 'made_available';
    case REVOKED_AVAILABILITY = 'revoked_availability';
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
}
