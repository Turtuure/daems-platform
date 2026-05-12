<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class MemberExpulsion
{
    public function __construct(
        public readonly MemberExpulsionId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $targetUserId,
        public readonly UserId $proposedByUserId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $hearingDeadlineAt,
        public readonly ?string $statementText,
        public readonly ?\DateTimeImmutable $statementReceivedAt,
        public readonly ?BoardDecisionId $decisionId,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?\DateTimeImmutable $expelledAt,
        public readonly ?\DateTimeImmutable $appealFiledAt,
        public readonly ?string $appealText,
        public readonly MemberExpulsionStatus $status,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
