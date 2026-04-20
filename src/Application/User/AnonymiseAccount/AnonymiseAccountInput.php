<?php

declare(strict_types=1);

namespace Daems\Application\User\AnonymiseAccount;

use Daems\Domain\Auth\ActingUser;

final class AnonymiseAccountInput
{
    public function __construct(
        public readonly string $targetUserId,
        public readonly ActingUser $acting,
    ) {}
}
