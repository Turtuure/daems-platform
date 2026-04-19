<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeMemberStatus;

use Daems\Domain\Auth\ActingUser;

final class ChangeMemberStatusInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $memberId,
        public readonly string $newStatus,
        public readonly string $reason,
    ) {}
}
