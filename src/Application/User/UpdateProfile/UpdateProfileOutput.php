<?php

declare(strict_types=1);

namespace Daems\Application\User\UpdateProfile;

final class UpdateProfileOutput
{
    public function __construct(
        public readonly ?string $error = null,
    ) {}
}
