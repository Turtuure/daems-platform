<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\User\UserId;

/**
 * Promotes the user to FULL: sets users.membership_type='FULL' and
 * users.invited_to_full_at = $at. The actual write is delegated via an
 * injected closure (bound to a real PDO repository in bootstrap/app.php).
 */
final class InviteFullExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(UserId, \DateTimeImmutable):void $userPromoter */
    public function __construct(private $userPromoter) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::InviteFull;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadTargetUserId === null) {
            throw new \DomainException('invite_full decision missing payload_target_user_id');
        }
        $promote = $this->userPromoter;
        $promote($d->payloadTargetUserId, $at);
    }
}
