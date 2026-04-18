<?php

declare(strict_types=1);

namespace Daems\Application\User\GetProfile;

final class GetProfileInput
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
