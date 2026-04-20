<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

/**
 * Factory for building ActingUser instances in unit tests.
 *
 * IDs must be UUID7-compliant strings (the UserId value object validates).
 */
final class ActingUserFactory
{
    public static function registeredInTenant(string $id, TenantId $tenantId): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($id),
            email:              'user-' . substr($id, -4) . '@test.local',
            isPlatformAdmin:    false,
            activeTenant:       $tenantId,
            roleInActiveTenant: UserTenantRole::Registered,
        );
    }

    public static function memberInTenant(string $id, TenantId $tenantId): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($id),
            email:              'member-' . substr($id, -4) . '@test.local',
            isPlatformAdmin:    false,
            activeTenant:       $tenantId,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }

    public static function adminInTenant(string $id, TenantId $tenantId): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($id),
            email:              'admin-' . substr($id, -4) . '@test.local',
            isPlatformAdmin:    false,
            activeTenant:       $tenantId,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    public static function platformAdmin(string $id, TenantId $tenantId): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($id),
            email:              'gsa-' . substr($id, -4) . '@test.local',
            isPlatformAdmin:    true,
            activeTenant:       $tenantId,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }
}
