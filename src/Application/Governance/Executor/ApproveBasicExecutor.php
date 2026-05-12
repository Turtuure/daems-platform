<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;

/**
 * Marks the linked member_application as approved and triggers the existing
 * member-activation pipeline. Side-effect is delegated to an injected closure
 * so this class stays free of repository churn — the closure is bound in
 * bootstrap/app.php to the same routine `ApproveMemberApplication` uses today.
 */
final class ApproveBasicExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId):void $approve */
    public function __construct(private $approve) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::ApproveBasic;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadApplicationId === null) {
            throw new \DomainException('approve_basic decision missing payload_application_id');
        }
        $approve = $this->approve;
        $approve($d->payloadApplicationId, $at, $d->viaDelegation ? $d->id->value() : null);
    }
}
