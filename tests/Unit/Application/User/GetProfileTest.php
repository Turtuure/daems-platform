<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetProfile\GetProfileInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetProfileTest extends TestCase
{
    private function makeUser(string $name = 'Jane Doe'): User
    {
        return new User(
            UserId::generate(),
            $name,
            'jane@example.com',
            password_hash('pass', PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    public function testReturnsProfileDataWhenUserExists(): void
    {
        $user = $this->makeUser();

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($user->id()->value()));

        $this->assertNull($out->error);
        $this->assertIsArray($out->profile);
        $this->assertSame('jane@example.com', $out->profile['email']);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $out = (new GetProfile($repo))->execute(new GetProfileInput('nonexistent-id'));

        $this->assertNull($out->profile);
        $this->assertNotNull($out->error);
    }

    public function testProfileContainsFirstAndLastNameFromFullName(): void
    {
        $user = $this->makeUser('Alice Smith');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($user->id()->value()));

        $this->assertSame('Alice', $out->profile['first_name']);
        $this->assertSame('Smith', $out->profile['last_name']);
    }

    public function testProfileWithSingleWordNameHasEmptyLastName(): void
    {
        $user = $this->makeUser('Cher');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($user->id()->value()));

        $this->assertSame('Cher', $out->profile['first_name']);
        $this->assertSame('', $out->profile['last_name']);
    }

    public function testProfileContainsAllExpectedKeys(): void
    {
        $user = $this->makeUser();

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $out = (new GetProfile($repo))->execute(new GetProfileInput($user->id()->value()));

        $expectedKeys = [
            'id', 'name', 'first_name', 'last_name', 'email', 'dob',
            'role', 'country', 'membership_type', 'membership_status',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $out->profile);
        }
    }
}
