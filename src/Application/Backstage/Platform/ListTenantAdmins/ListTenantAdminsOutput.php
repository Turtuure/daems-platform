<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenantAdmins;

/**
 * Read-model row shape for one admin in a tenant:
 *   - userId:    string (UUID)
 *   - name:      string (from users.name)
 *   - email:     string (from users.email)
 *   - grantedAt: string (ISO8601)
 *
 * @phpstan-type AdminRow array{
 *   userId:    string,
 *   name:      string,
 *   email:     string,
 *   grantedAt: string,
 * }
 */
final class ListTenantAdminsOutput
{
    /** @param list<AdminRow> $admins */
    public function __construct(
        public readonly array $admins,
        public readonly int $total,
    ) {}
}
