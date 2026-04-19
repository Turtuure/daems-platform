<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

use Daems\Domain\User\User;

/**
 * Sealed result of login: either (authenticated User) OR error, never both.
 * Carries the User entity rather than a dict so the controller can issue
 * a token without a second DB round-trip. The wire payload is shaped by
 * AuthController from the User fields.
 */
final class LoginUserOutput
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?string $error,
    ) {}

    public static function success(User $user): self
    {
        return new self($user, null);
    }

    public static function failure(string $error): self
    {
        return new self(null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
