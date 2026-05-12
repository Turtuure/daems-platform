<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class SubmitExpulsionStatement
{
    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        string $statementText,
        \DateTimeImmutable $at,
    ): void {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");

        // Only the target user may submit their own statement here.
        if (!$e->targetUserId->equals($actingUserId)) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot submit this statement");
        }
        if ($e->status !== MemberExpulsionStatus::Hearing) {
            throw new \DomainException("statement only accepted in 'hearing' status; current={$e->status->value}");
        }
        if ($at > $e->hearingDeadlineAt) {
            throw new \DomainException('hearing deadline has elapsed');
        }
        if (trim($statementText) === '') {
            throw new \InvalidArgumentException('statement_text required');
        }

        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       trim($statementText),
            statementReceivedAt: $at,
            decisionId:          $e->decisionId,
            decidedAt:           $e->decidedAt,
            expelledAt:          $e->expelledAt,
            appealFiledAt:       $e->appealFiledAt,
            appealText:          $e->appealText,
            status:              $e->status,
            createdAt:           $e->createdAt,
        ));
    }
}
