<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberTermEndedReason;

final class RemoveBoardMemberExecutor implements BoardDecisionExecutorInterface
{
    public function __construct(
        private readonly BoardMemberRepositoryInterface $members,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::RemoveBoardMember;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadBoardMemberId === null) {
            throw new \DomainException('remove_board_member decision missing payload_board_member_id');
        }
        $existing = $this->members->find($d->payloadBoardMemberId);
        if ($existing === null) return;
        $this->members->save(new BoardMember(
            id:              $existing->id,
            boardId:         $existing->boardId,
            userId:          $existing->userId,
            role:            $existing->role,
            termStartedAt:   $existing->termStartedAt,
            termEndsAt:      $existing->termEndsAt,
            termEndedAt:     $at,
            termEndedReason: BoardMemberTermEndedReason::Removed,
        ));
    }
}
