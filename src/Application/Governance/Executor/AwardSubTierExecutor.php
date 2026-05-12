<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

/**
 * Sets users.membership_subtier and writes the audit row in member_sub_tier_awards.
 * The users update is delegated to an injected closure.
 */
final class AwardSubTierExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(UserId, string):void $applyToUser sets users.membership_subtier
     *  @param callable(BoardId):TenantId $tenantOfBoard
     */
    public function __construct(
        private $applyToUser,
        private $tenantOfBoard,
        private readonly MemberSubTierAwardRepositoryInterface $awards,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::AwardSubTier;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadTargetUserId === null || $d->payloadSubTierSlug === null) {
            throw new \DomainException('award_subtier decision missing payload (target_user_id or sub_tier_slug)');
        }
        $apply = $this->applyToUser;
        $apply($d->payloadTargetUserId, $d->payloadSubTierSlug);

        $tenantLookup = $this->tenantOfBoard;
        $tenantId = $tenantLookup($d->boardId);

        $this->awards->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $tenantId,
            userId: $d->payloadTargetUserId,
            subTierSlug: $d->payloadSubTierSlug,
            decisionId: $d->id,
            awardedAt: $at,
            revokedAt: null,
            revokeDecisionId: null,
        ));
    }
}
