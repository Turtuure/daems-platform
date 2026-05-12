<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class MemberSubTierAward
{
    public function __construct(
        public readonly MemberSubTierAwardId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $userId,
        public readonly string $subTierSlug,
        public readonly BoardDecisionId $decisionId,
        public readonly \DateTimeImmutable $awardedAt,
        public readonly ?\DateTimeImmutable $revokedAt,
        public readonly ?BoardDecisionId $revokeDecisionId,
    ) {}

    public function isActive(\DateTimeImmutable $at): bool
    {
        return $this->revokedAt === null || $at < $this->revokedAt;
    }
}
