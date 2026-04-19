<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class LoginUser
{
    /**
     * Static dummy bcrypt hash used as a timing-oracle defence: when the
     * submitted email is unknown, we still run password_verify against this
     * hash so the response time of a bad-email probe is indistinguishable
     * from a bad-password probe. The hash is of the constant string "timing"
     * generated at PHP load time; it would only match if an attacker chose
     * "timing" as their password AND there were no user rows, which still
     * fails the null-user short-circuit below.
     */
    private const DUMMY_BCRYPT_HASH = '$2y$10$Tq1NoizjoZZF2dI.7nQ7I.8fhBCKTmWM.3hZjWh9n1ZvfEhKtH7YK';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
    ) {}

    public function execute(LoginUserInput $input): LoginUserOutput
    {
        $now = $this->clock->now();

        if (strlen($input->password) > 72) {
            $this->attempts->record($input->ip, $input->email, false, $now);
            return LoginUserOutput::failure('Invalid email or password.');
        }

        $user = $this->users->findByEmail($input->email);

        // Always run password_verify — against the real hash if the user exists,
        // or a dummy hash otherwise. Short-circuiting would leak email existence
        // via response-time deltas.
        $candidateHash = $user?->passwordHash() ?? self::DUMMY_BCRYPT_HASH;
        $passwordMatches = password_verify($input->password, $candidateHash);

        $ok = $user !== null && $passwordMatches;
        $this->attempts->record($input->ip, $input->email, $ok, $now);

        if (!$ok) {
            return LoginUserOutput::failure('Invalid email or password.');
        }

        return LoginUserOutput::success($user);
    }
}
