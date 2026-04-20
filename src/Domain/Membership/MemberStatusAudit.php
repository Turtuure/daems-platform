<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

final class MemberStatusAudit
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly ?string $previousStatus,
        public readonly string $newStatus,
        public readonly string $reason,
        public readonly string $performedByAdminId,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
