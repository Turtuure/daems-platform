<?php

declare(strict_types=1);

namespace Daems\Application\User\GetProfile;

use Daems\Domain\Auth\ActingUser;

final class GetProfileInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
    ) {}
}
