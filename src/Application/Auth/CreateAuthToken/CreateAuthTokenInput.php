<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\User\UserId;

final class CreateAuthTokenInput
{
    public function __construct(
        public readonly UserId $userId,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
    ) {}
}
