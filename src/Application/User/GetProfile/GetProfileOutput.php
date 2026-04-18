<?php

declare(strict_types=1);

namespace Daems\Application\User\GetProfile;

final class GetProfileOutput
{
    public function __construct(
        public readonly ?array $profile,
        public readonly ?string $error = null,
    ) {}
}
