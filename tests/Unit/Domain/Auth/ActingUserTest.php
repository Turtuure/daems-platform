<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ActingUserTest extends TestCase
{
    public function testIsAdminTrueForAdminRole(): void
    {
        $a = new ActingUser(UserId::generate(), 'admin');
        $this->assertTrue($a->isAdmin());
    }

    public function testIsAdminFalseForOtherRoles(): void
    {
        foreach (['registered', 'member', 'supporter'] as $role) {
            $this->assertFalse((new ActingUser(UserId::generate(), $role))->isAdmin(), $role);
        }
    }

    public function testOwnsReturnsTrueForSameId(): void
    {
        $id = UserId::generate();
        $a = new ActingUser($id, 'registered');
        $this->assertTrue($a->owns($id));
    }

    public function testOwnsReturnsFalseForDifferentId(): void
    {
        $a = new ActingUser(UserId::generate(), 'registered');
        $this->assertFalse($a->owns(UserId::generate()));
    }
}
