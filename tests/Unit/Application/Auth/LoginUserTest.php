<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class LoginUserTest extends TestCase
{
    private function makeUser(string $plainPassword): User
    {
        return new User(
            UserId::generate(),
            'Jane Doe',
            'jane@example.com',
            password_hash($plainPassword, PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    public function testReturnsUserDataOnValidCredentials(): void
    {
        $user = $this->makeUser('correct-pass');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = (new LoginUser($repo))->execute(new LoginUserInput('jane@example.com', 'correct-pass'));

        $this->assertNull($out->error);
        $this->assertIsArray($out->user);
        $this->assertSame('jane@example.com', $out->user['email']);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);

        $out = (new LoginUser($repo))->execute(new LoginUserInput('unknown@example.com', 'pass'));

        $this->assertNull($out->user);
        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorOnWrongPassword(): void
    {
        $user = $this->makeUser('correct-pass');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = (new LoginUser($repo))->execute(new LoginUserInput('jane@example.com', 'wrong-pass'));

        $this->assertNull($out->user);
        $this->assertNotNull($out->error);
    }

    public function testOutputUserContainsExpectedKeys(): void
    {
        $user = $this->makeUser('pass123');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = (new LoginUser($repo))->execute(new LoginUserInput('jane@example.com', 'pass123'));

        foreach (['id', 'name', 'email', 'dob', 'role', 'membership_type', 'membership_status'] as $key) {
            $this->assertArrayHasKey($key, $out->user);
        }
    }
}
