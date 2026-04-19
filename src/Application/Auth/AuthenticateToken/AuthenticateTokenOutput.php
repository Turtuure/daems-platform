<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;

/**
 * Sealed result of authentication: either (userId + email + isPlatformAdmin + tokenId) OR error, never both.
 * Private constructor + named factories make illegal states unrepresentable.
 *
 * Tenant wiring is intentionally absent here — it is the responsibility of
 * AuthMiddleware to stitch userId/email/isPlatformAdmin together with the
 * request-scoped tenant and the user's role in that tenant.
 */
final class AuthenticateTokenOutput
{
    private function __construct(
        public readonly ?UserId $userId,
        public readonly ?string $email,
        public readonly bool $isPlatformAdmin,
        public readonly ?AuthTokenId $tokenId,
        public readonly ?string $error,
    ) {}

    public static function success(
        UserId $userId,
        string $email,
        bool $isPlatformAdmin,
        AuthTokenId $tokenId,
    ): self {
        return new self($userId, $email, $isPlatformAdmin, $tokenId, null);
    }

    public static function failure(string $error): self
    {
        return new self(null, null, false, null, $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
