<?php

declare(strict_types=1);

namespace Daems\Application\User\ChangePassword;

final class ChangePasswordInput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $currentPassword,
        public readonly string $newPassword,
    ) {}
}
