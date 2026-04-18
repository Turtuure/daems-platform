<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

final class DeleteAccountInput
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
