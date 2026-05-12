<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

/**
 * Sets users.membership_status='expelled' and updates the linked member_expulsions row.
 * The user-side update is delegated to an injected closure.
 */
final class ExpelExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(UserId, string $reason, \DateTimeImmutable):void $expelUser */
    public function __construct(
        private $expelUser,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::Expel;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadTargetUserId === null) {
            throw new \DomainException('expel decision missing payload_target_user_id');
        }

        $expulsion = $this->expulsions->findByDecisionId($d->id);
        if ($expulsion === null) {
            throw new \DomainException("no expulsion linked to decision={$d->id->value()}");
        }

        $expel = $this->expelUser;
        $expel($d->payloadTargetUserId, $d->payloadReason ?? 'Erottaminen § 4', $at);

        $this->expulsions->save(new MemberExpulsion(
            id:                  $expulsion->id,
            tenantId:            $expulsion->tenantId,
            targetUserId:        $expulsion->targetUserId,
            proposedByUserId:    $expulsion->proposedByUserId,
            reason:              $expulsion->reason,
            hearingDeadlineAt:   $expulsion->hearingDeadlineAt,
            statementText:       $expulsion->statementText,
            statementReceivedAt: $expulsion->statementReceivedAt,
            decisionId:          $expulsion->decisionId,
            decidedAt:           $at,
            expelledAt:          $at,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::Expelled,
            createdAt:           $expulsion->createdAt,
        ));
    }
}
