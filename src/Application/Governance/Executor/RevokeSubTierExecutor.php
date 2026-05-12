<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

/**
 * Clears users.membership_subtier = NULL and sets revoked_at on the active
 * member_sub_tier_awards row. User-side update delegated via closure.
 */
final class RevokeSubTierExecutor implements BoardDecisionExecutorInterface
{
    /**
     * @param callable(UserId):void $clearUserSubtier  sets users.membership_subtier = NULL
     * @param callable(BoardId):TenantId $tenantOfBoard
     */
    public function __construct(
        private $clearUserSubtier,
        private $tenantOfBoard,
        private readonly MemberSubTierAwardRepositoryInterface $awards,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::RevokeSubTier;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadTargetUserId === null) {
            throw new \DomainException('revoke_subtier decision missing payload_target_user_id');
        }

        $clear = $this->clearUserSubtier;
        $clear($d->payloadTargetUserId);

        $tenantLookup = $this->tenantOfBoard;
        $tenantId = $tenantLookup($d->boardId);

        $active = $this->awards->findActive($tenantId, $d->payloadTargetUserId, $at);
        if ($active !== null) {
            $this->awards->save(new MemberSubTierAward(
                id:               $active->id,
                tenantId:         $active->tenantId,
                userId:           $active->userId,
                subTierSlug:      $active->subTierSlug,
                decisionId:       $active->decisionId,
                awardedAt:        $active->awardedAt,
                revokedAt:        $at,
                revokeDecisionId: $d->id,
            ));
        }
    }
}
