<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\Auth\AuthTokenId;
use DateTimeImmutable;

final class CreateAuthTokenOutput
{
    public function __construct(
        public readonly AuthTokenId $id,
        public readonly string $rawToken,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
