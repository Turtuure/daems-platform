<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenId;

final class AuthenticateTokenOutput
{
    public function __construct(
        public readonly ?ActingUser $actingUser,
        public readonly ?AuthTokenId $tokenId,
        public readonly ?string $error,
    ) {}
}
