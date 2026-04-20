<?php

declare(strict_types=1);

namespace Daems\Application\Invite\IssueInvite;

final class IssueInviteInput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
    ) {}
}
