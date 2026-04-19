<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\Role;
use Daems\Domain\User\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActingUserTest extends TestCase
{
    public function testIsAdminTrueForAdminRole(): void
    {
        $a = new ActingUser(UserId::generate(), Role::Admin);
        $this->assertTrue($a->isAdmin());
    }

    public function testIsAdminFalseForOtherRoles(): void
    {
        foreach ([Role::Registered, Role::Member, Role::Supporter, Role::Moderator] as $role) {
            $this->assertFalse((new ActingUser(UserId::generate(), $role))->isAdmin(), $role->value);
        }
    }

    public function testOwnsReturnsTrueForSameId(): void
    {
        $id = UserId::generate();
        $a = new ActingUser($id, Role::Registered);
        $this->assertTrue($a->owns($id));
    }

    public function testOwnsReturnsFalseForDifferentId(): void
    {
        $a = new ActingUser(UserId::generate(), Role::Registered);
        $this->assertFalse($a->owns(UserId::generate()));
    }

    public function testAcceptsStringRoleViaLoosenedCtor(): void
    {
        $a = new ActingUser(UserId::generate(), 'admin');
        $this->assertTrue($a->isAdmin());
        $this->assertSame(Role::Admin, $a->role);
    }

    /**
     * The security-critical behaviour this type exists to enforce:
     * a typo like 'Admin' or 'adminstrator' must NOT silently demote
     * the caller to a non-admin — it must fail loudly.
     */
    public function testRejectsUnknownRoleStringWithException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActingUser(UserId::generate(), 'Admin'); // capital-A typo
    }

    public function testRejectsArbitraryGarbageStringWithException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActingUser(UserId::generate(), 'superadmin');
    }
}
