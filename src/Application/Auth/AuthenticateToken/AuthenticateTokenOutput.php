<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenId;

/**
 * Sealed result of authentication: either (actingUser + tokenId) OR error, never both.
 * Private constructor + named factories make illegal states unrepresentable.
 */
final class AuthenticateTokenOutput
{
    private function __construct(
        public readonly ?ActingUser $actingUser,
        public readonly ?AuthTokenId $tokenId,
        public readonly ?string $error,
    ) {}

    public static function success(ActingUser $actingUser, AuthTokenId $tokenId): self
    {
        return new self($actingUser, $tokenId, null);
    }

    public static function failure(string $error): self
    {
        return new self(null, null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
