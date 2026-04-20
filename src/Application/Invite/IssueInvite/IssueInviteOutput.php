<?php

declare(strict_types=1);

namespace Daems\Application\Invite\IssueInvite;

final class IssueInviteOutput
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $inviteUrl,
        public readonly \DateTimeImmutable $expiresAt,
    ) {}
}
