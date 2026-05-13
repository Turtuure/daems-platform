<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\LapseInactiveMember;

final class LapseInactiveMemberOutput
{
    public function __construct(
        public readonly bool   $lapsed,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
