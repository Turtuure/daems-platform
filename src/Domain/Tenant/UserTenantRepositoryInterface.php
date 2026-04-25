<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;

interface UserTenantRepositoryInterface
{
    /** Returns null if user has no active membership in the given tenant. */
    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole;

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void;

    /** Soft-departure: sets left_at. */
    public function detach(UserId $userId, TenantId $tenantId): void;

    /** @return list<UserTenantRole> active memberships for this user */
    public function rolesForUser(UserId $userId): array;

    /** Mark all active tenant memberships for a user as left. */
    public function markAllLeftForUser(string $userId, \DateTimeImmutable $now): void;

    /**
     * Aggregate membership stats for the backstage Members dashboard.
     *
     * Each KPI returns a value (full-history total) plus a 30-entry zero-filled
     * daily sparkline (today = entry 29, 29 days ago = entry 0). Date strings
     * are 'YYYY-MM-DD'.
     *
     * - total_members: count of active memberships in tenant; sparkline is
     *   daily count of joined_at over the last 30 days.
     * - new_members:   count of memberships joined in the last 30 days;
     *   sparkline mirrors total_members (same daily joined_at series).
     * - supporters:    count of active members whose users.membership_type =
     *   'supporter'; sparkline is daily joined_at filtered to supporters.
     * - inactive:      count of active members whose users.membership_status =
     *   'inactive'; sparkline is intentionally [] — the use case fills it
     *   from the MemberStatusAuditRepository (see Task 3.3).
     *
     * @return array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function membershipStatsForTenant(TenantId $tenantId): array;
}
