<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\Exception\AppealAlreadyFiled;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class FileExpulsionAppeal
{
    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        string $appealText,
        \DateTimeImmutable $at,
    ): void {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");
        if (!$e->targetUserId->equals($actingUserId)) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot appeal this expulsion");
        }
        if ($e->status !== MemberExpulsionStatus::Expelled) {
            throw new \DomainException("appeal only allowed after expulsion is final; status={$e->status->value}");
        }
        if ($e->appealFiledAt !== null) {
            throw new AppealAlreadyFiled("expulsion={$expulsionId->value()}");
        }
        if (trim($appealText) === '') {
            throw new \InvalidArgumentException('appeal_text required');
        }

        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       $e->statementText,
            statementReceivedAt: $e->statementReceivedAt,
            decisionId:          $e->decisionId,
            decidedAt:           $e->decidedAt,
            expelledAt:          $e->expelledAt,
            appealFiledAt:       $at,
            appealText:          trim($appealText),
            status:              MemberExpulsionStatus::Appealed,
            createdAt:           $e->createdAt,
        ));
    }
}
