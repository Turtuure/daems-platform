<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

use Daems\Domain\Auth\ActingUser;

final class DeleteAccountInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
    ) {}
}
