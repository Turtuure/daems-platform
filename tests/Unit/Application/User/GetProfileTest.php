<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetProfile\GetProfileInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetProfileTest extends TestCase
{
    private function makeUser(string $name = 'Jane Doe', ?UserId $id = null): User
    {
        return new User(
            $id ?? UserId::generate(),
            $name,
            'jane@example.com',
            password_hash('pass', PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    private function self(UserId $id): ActingUser
    {
        return new ActingUser($id, 'registered');
    }

    public function testReturnsProfileDataWhenSelf(): void
    {
        $id = UserId::generate();
        $user = $this->makeUser('Jane Doe', $id);

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($this->self($id), $id->value()));

        $this->assertNull($out->error);
        $this->assertIsArray($out->profile);
        $this->assertSame('jane@example.com', $out->profile['email']);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($this->self(UserId::generate()), 'nonexistent-id'));

        $this->assertNull($out->profile);
        $this->assertNotNull($out->error);
    }

    public function testProfileContainsFirstAndLastNameFromFullNameForSelf(): void
    {
        $id = UserId::generate();
        $user = $this->makeUser('Alice Smith', $id);

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($this->self($id), $id->value()));

        $this->assertSame('Alice', $out->profile['first_name']);
        $this->assertSame('Smith', $out->profile['last_name']);
    }

    public function testProfileContainsAllExpectedKeysForSelf(): void
    {
        $id = UserId::generate();
        $user = $this->makeUser('Jane Doe', $id);

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($this->self($id), $id->value()));

        $expectedKeys = [
            'id', 'name', 'first_name', 'last_name', 'email', 'dob',
            'role', 'country', 'membership_type', 'membership_status',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $out->profile);
        }
    }

    public function testAdminSeesFullProfileOfOtherUser(): void
    {
        $user = $this->makeUser('Jane Doe');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $admin = new ActingUser(UserId::generate(), 'admin');
        $out = (new GetProfile($repo))->execute(new GetProfileInput($admin, $user->id()->value()));

        $this->assertArrayHasKey('dob', $out->profile);
        $this->assertArrayHasKey('address_street', $out->profile);
    }

    public function testOtherUserSeesReducedView(): void
    {
        $user = $this->makeUser('Jane Doe');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $other = new ActingUser(UserId::generate(), 'registered');
        $out = (new GetProfile($repo))->execute(new GetProfileInput($other, $user->id()->value()));

        $this->assertNull($out->error);
        $this->assertSame(['id', 'name'], array_keys($out->profile));
        $this->assertArrayNotHasKey('dob', $out->profile);
        $this->assertArrayNotHasKey('address_street', $out->profile);
        $this->assertArrayNotHasKey('email', $out->profile);
    }
}
