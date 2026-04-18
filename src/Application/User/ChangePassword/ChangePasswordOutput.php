<?php

declare(strict_types=1);

namespace Daems\Application\User\ChangePassword;

final class ChangePasswordOutput
{
    public function __construct(
        public readonly ?string $error = null,
    ) {}
}
