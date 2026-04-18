<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\FrozenClock;
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

    private function uc(?UserRepositoryInterface $repo = null, ?InMemoryAuthLoginAttemptRepository $attempts = null): LoginUser
    {
        return new LoginUser(
            $repo ?? $this->createMock(UserRepositoryInterface::class),
            $attempts ?? new InMemoryAuthLoginAttemptRepository(),
            FrozenClock::at('2026-04-19T12:00:00Z'),
        );
    }

    public function testReturnsUserDataOnValidCredentials(): void
    {
        $user = $this->makeUser('correct-pass');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = $this->uc($repo)->execute(new LoginUserInput('jane@example.com', 'correct-pass', '1.2.3.4'));

        $this->assertNull($out->error);
        $this->assertIsArray($out->user);
        $this->assertSame('jane@example.com', $out->user['email']);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);

        $out = $this->uc($repo)->execute(new LoginUserInput('unknown@example.com', 'pass', '1.2.3.4'));

        $this->assertNull($out->user);
        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorOnWrongPassword(): void
    {
        $user = $this->makeUser('correct-pass');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = $this->uc($repo)->execute(new LoginUserInput('jane@example.com', 'wrong-pass', '1.2.3.4'));

        $this->assertNull($out->user);
        $this->assertNotNull($out->error);
    }

    public function testOutputUserContainsExpectedKeys(): void
    {
        $user = $this->makeUser('pass123');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = $this->uc($repo)->execute(new LoginUserInput('jane@example.com', 'pass123', '1.2.3.4'));

        foreach (['id', 'name', 'email', 'dob', 'role', 'membership_type', 'membership_status'] as $key) {
            $this->assertArrayHasKey($key, $out->user);
        }
    }

    public function testRecordsSuccessfulAttempt(): void
    {
        $user = $this->makeUser('correct-pass');
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $this->uc($repo, $attempts)->execute(new LoginUserInput('jane@example.com', 'correct-pass', '1.2.3.4'));

        $this->assertCount(1, $attempts->rows);
        $this->assertTrue($attempts->rows[0]['success']);
    }

    public function testRecordsFailedAttempt(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $this->uc($repo, $attempts)->execute(new LoginUserInput('nope@example.com', 'x', '1.2.3.4'));

        $this->assertCount(1, $attempts->rows);
        $this->assertFalse($attempts->rows[0]['success']);
    }

    public function testRejectsPasswordLongerThan72Bytes(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $out = $this->uc($repo, $attempts)
            ->execute(new LoginUserInput('x@example.com', str_repeat('a', 73), '1.2.3.4'));

        $this->assertNotNull($out->error);
        $this->assertCount(1, $attempts->rows);
        $this->assertFalse($attempts->rows[0]['success']);
    }
}
