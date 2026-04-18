<?php

declare(strict_types=1);

namespace Daems\Application\User\ChangePassword;

use Daems\Domain\Auth\ActingUser;

final class ChangePasswordInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
        public readonly string $currentPassword,
        public readonly string $newPassword,
    ) {}
}
