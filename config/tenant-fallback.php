<?php

declare(strict_types=1);

/**
 * Host → tenant slug fallback map.
 *
 * Consulted by HostTenantResolver AFTER the primary DB lookup (tenant_domains table).
 * Use this for dev-only hosts and any runtime host that isn't yet registered
 * in the tenant_domains table.
 */
return [
    'daem-society.local'   => 'daems',
    'daems-platform.local' => 'daems',
    'sahegroup.local'      => 'sahegroup',
    'localhost'            => 'daems',
];
