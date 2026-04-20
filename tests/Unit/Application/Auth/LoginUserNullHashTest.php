<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LoginUserNullHashTest extends TestCase
{
    private const USER_ID = '01958000-0000-7000-8000-000000000070';

    private function makeUc(
        InMemoryUserRepository $users,
        InMemoryAdminApplicationDismissalRepository $dismissals,
        ?InMemoryAuthLoginAttemptRepository $attempts = null,
    ): LoginUser {
        return new LoginUser(
            $users,
            $attempts ?? new InMemoryAuthLoginAttemptRepository(),
            $dismissals,
            FrozenClock::at('2026-04-20T12:00:00Z'),
        );
    }

    public function test_login_with_null_password_hash_fails_generic(): void
    {
        $users = new InMemoryUserRepository();
        $user  = new User(
            UserId::fromString(self::USER_ID),
            'Pending User',
            'pending@example.com',
            null, // null password hash — invite-pending account
            null,
        );
        $users->save($user);

        $dismissals = new InMemoryAdminApplicationDismissalRepository();

        $uc  = $this->makeUc($users, $dismissals);
        $out = $uc->execute(new LoginUserInput('pending@example.com', 'anypassword', '1.2.3.4'));

        self::assertFalse($out->isSuccess());
        self::assertSame('Invalid email or password.', $out->error);
        self::assertNull($out->user);
    }

    public function test_login_success_clears_all_dismissals_for_admin(): void
    {
        $users = new InMemoryUserRepository();
        $user  = new User(
            UserId::fromString(self::USER_ID),
            'Admin User',
            'admin@example.com',
            password_hash('correctpassword', PASSWORD_BCRYPT),
            '1990-01-01',
        );
        $users->save($user);

        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        // Seed two dismissals for this admin
        $dismissals->save(new AdminApplicationDismissal(
            'dis-1',
            self::USER_ID,
            '01958000-0000-7000-8000-000000000081',
            'member',
            new DateTimeImmutable('2026-04-20 11:00:00'),
        ));
        $dismissals->save(new AdminApplicationDismissal(
            'dis-2',
            self::USER_ID,
            '01958000-0000-7000-8000-000000000082',
            'supporter',
            new DateTimeImmutable('2026-04-20 11:00:00'),
        ));

        self::assertCount(2, $dismissals->listAppIdsDismissedByAdmin(self::USER_ID));

        $uc  = $this->makeUc($users, $dismissals);
        $out = $uc->execute(new LoginUserInput('admin@example.com', 'correctpassword', '1.2.3.4'));

        self::assertTrue($out->isSuccess());
        self::assertEmpty($dismissals->listAppIdsDismissedByAdmin(self::USER_ID));
    }

    public function test_login_failure_does_not_clear_dismissals(): void
    {
        $users = new InMemoryUserRepository();
        $user  = new User(
            UserId::fromString(self::USER_ID),
            'Admin User',
            'admin2@example.com',
            password_hash('correctpassword', PASSWORD_BCRYPT),
            '1990-01-01',
        );
        $users->save($user);

        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $dismissals->save(new AdminApplicationDismissal(
            'dis-3',
            self::USER_ID,
            '01958000-0000-7000-8000-000000000083',
            'member',
            new DateTimeImmutable('2026-04-20 11:00:00'),
        ));

        $uc  = $this->makeUc($users, $dismissals);
        $out = $uc->execute(new LoginUserInput('admin2@example.com', 'wrongpassword', '1.2.3.4'));

        self::assertFalse($out->isSuccess());
        self::assertCount(1, $dismissals->listAppIdsDismissedByAdmin(self::USER_ID));
    }
}
