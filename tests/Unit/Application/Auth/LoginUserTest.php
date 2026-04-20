<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
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

    private function uc(
        ?UserRepositoryInterface $repo = null,
        ?InMemoryAuthLoginAttemptRepository $attempts = null,
        ?InMemoryAdminApplicationDismissalRepository $dismissals = null,
    ): LoginUser {
        return new LoginUser(
            $repo ?? $this->createMock(UserRepositoryInterface::class),
            $attempts ?? new InMemoryAuthLoginAttemptRepository(),
            $dismissals ?? new InMemoryAdminApplicationDismissalRepository(),
            FrozenClock::at('2026-04-19T12:00:00Z'),
        );
    }

    public function testReturnsUserEntityOnValidCredentials(): void
    {
        $user = $this->makeUser('correct-pass');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);

        $out = $this->uc($repo)->execute(new LoginUserInput('jane@example.com', 'correct-pass', '1.2.3.4'));

        $this->assertTrue($out->isSuccess());
        $this->assertNull($out->error);
        $this->assertSame($user, $out->user);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);

        $out = $this->uc($repo)->execute(new LoginUserInput('unknown@example.com', 'pass', '1.2.3.4'));

        $this->assertFalse($out->isSuccess());
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

    public function testRecordsSuccessfulAttemptWithCorrectIpAndEmail(): void
    {
        $user = $this->makeUser('correct-pass');
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($user);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $this->uc($repo, $attempts)->execute(new LoginUserInput('jane@example.com', 'correct-pass', '1.2.3.4'));

        $this->assertCount(1, $attempts->rows);
        $this->assertTrue($attempts->rows[0]['success']);
        $this->assertSame('jane@example.com', $attempts->rows[0]['email']);
        $this->assertSame('1.2.3.4', $attempts->rows[0]['ip']);
    }

    public function testRecordsFailedAttemptWithCorrectIpAndEmail(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $this->uc($repo, $attempts)->execute(new LoginUserInput('nope@example.com', 'x', '5.6.7.8'));

        $this->assertCount(1, $attempts->rows);
        $this->assertFalse($attempts->rows[0]['success']);
        $this->assertSame('nope@example.com', $attempts->rows[0]['email']);
        $this->assertSame('5.6.7.8', $attempts->rows[0]['ip']);
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
        $this->assertSame('x@example.com', $attempts->rows[0]['email']);
    }

    /**
     * Regression: even when user is unknown, LoginUser must run password_verify
     * against a dummy hash to eliminate the timing-delta oracle. We cannot
     * directly assert timing here (too flaky), so we assert that the
     * repository is queried AND the attempt is recorded — together they
     * imply the verify path ran. Exact timing is left to production monitoring.
     */
    public function testDoesNotShortCircuitOnUnknownEmail(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->expects($this->once())->method('findByEmail')->willReturn(null);
        $attempts = new InMemoryAuthLoginAttemptRepository();

        $start = hrtime(true);
        $this->uc($repo, $attempts)->execute(new LoginUserInput('ghost@example.com', 'x', '1.2.3.4'));
        $elapsed = hrtime(true) - $start;

        $this->assertCount(1, $attempts->rows);
        // bcrypt at cost 10 takes roughly ~40ms even on fast hardware;
        // a short-circuited branch would typically be <1ms. 10ms is a
        // conservative floor that reliably catches the short-circuit.
        $this->assertGreaterThan(10_000_000, $elapsed, 'expected bcrypt-level elapsed time even with unknown user');
    }
}
