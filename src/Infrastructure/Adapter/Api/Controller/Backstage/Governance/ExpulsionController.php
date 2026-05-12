<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Membership\AdvanceExpulsionToVote;
use Daems\Application\Membership\FileExpulsionAppeal;
use Daems\Application\Membership\InitiateMemberExpulsion;
use Daems\Application\Membership\InitiateMemberExpulsionInput;
use Daems\Application\Membership\SubmitExpulsionStatement;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ExpulsionController
{
    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
        private readonly InitiateMemberExpulsion $initiate,
        private readonly SubmitExpulsionStatement $submitStatement,
        private readonly AdvanceExpulsionToVote $advance,
        private readonly FileExpulsionAppeal $appeal,
    ) {}

    public function index(Request $req): Response
    {
        $actor  = $req->requireActingUser();
        $status = $req->query('status');
        $statusE = is_string($status) && $status !== '' ? MemberExpulsionStatus::tryFrom($status) : null;
        $rows = [];
        foreach ($this->expulsions->listForTenant($actor->activeTenant, $statusE) as $e) {
            $rows[] = $this->serialize($e);
        }
        return Response::json(['data' => $rows]);
    }

    public function show(Request $req, string $id): Response
    {
        $req->requireActingUser();
        $e = $this->expulsions->find(MemberExpulsionId::fromString($id))
            ?? throw new \DomainException('expulsion not found');
        return Response::json(['expulsion' => $this->serialize($e)]);
    }

    public function initiate(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $targetUserId = is_string($body['target_user_id'] ?? null) ? $body['target_user_id'] : throw new \DomainException('target_user_id required');
        $reason       = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        $id = $this->initiate->execute(new InitiateMemberExpulsionInput(
            tenantId: $actor->activeTenant,
            targetUserId: UserId::fromString($targetUserId),
            proposedByUserId: $actor->id,
            reason: $reason,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['expulsion_id' => $id->value()], 201);
    }

    public function submitStatement(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $text  = is_string($body['statement_text'] ?? null) ? $body['statement_text'] : '';
        $this->submitStatement->execute(
            MemberExpulsionId::fromString($id),
            $actor->id,
            $text,
            new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }

    public function advance(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $mref  = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : null;
        $decisionId = $this->advance->execute(
            MemberExpulsionId::fromString($id),
            $actor->id,
            new \DateTimeImmutable(),
            $mref,
        );
        return Response::json(['decision_id' => $decisionId->value()], 201);
    }

    public function appeal(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $text  = is_string($body['appeal_text'] ?? null) ? $body['appeal_text'] : '';
        $this->appeal->execute(
            MemberExpulsionId::fromString($id),
            $actor->id,
            $text,
            new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }

    private function serialize(\Daems\Domain\Membership\MemberExpulsion $e): array
    {
        return [
            'id'                    => $e->id->value(),
            'target_user_id'        => $e->targetUserId->value(),
            'proposed_by_user_id'   => $e->proposedByUserId->value(),
            'reason'                => $e->reason,
            'hearing_deadline_at'   => $e->hearingDeadlineAt->format(\DateTimeInterface::ATOM),
            'statement_text'        => $e->statementText,
            'statement_received_at' => $e->statementReceivedAt?->format(\DateTimeInterface::ATOM),
            'decision_id'           => $e->decisionId?->value(),
            'decided_at'            => $e->decidedAt?->format(\DateTimeInterface::ATOM),
            'expelled_at'           => $e->expelledAt?->format(\DateTimeInterface::ATOM),
            'appeal_filed_at'       => $e->appealFiledAt?->format(\DateTimeInterface::ATOM),
            'appeal_text'           => $e->appealText,
            'status'                => $e->status->value,
            'created_at'            => $e->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
