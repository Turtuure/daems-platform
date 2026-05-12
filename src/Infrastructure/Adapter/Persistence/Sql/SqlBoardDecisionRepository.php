<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardDecisionRepository implements BoardDecisionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(BoardDecisionId $id): ?BoardDecision
    {
        $stmt = $this->pdo->prepare('SELECT * FROM board_decisions WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listForBoard(BoardId $boardId, ?BoardDecisionStatus $status = null, ?BoardDecisionType $type = null): array
    {
        $sql    = 'SELECT * FROM board_decisions WHERE board_id = ?';
        $params = [$boardId->value()];
        if ($status !== null) { $sql .= ' AND status = ?';        $params[] = $status->value; }
        if ($type   !== null) { $sql .= ' AND decision_type = ?'; $params[] = $type->value;   }
        $sql .= ' ORDER BY proposed_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function listExpiredPending(\DateTimeImmutable $at, int $limit = 100): array
    {
        // Cast to int to be safe (no SQL injection via interpolated LIMIT).
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM board_decisions
              WHERE status = 'pending' AND expires_at < ?
              ORDER BY expires_at ASC
              LIMIT {$limit}"
        );
        $stmt->execute([$at->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function save(BoardDecision $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_decisions
                (id, board_id, decision_type, threshold, mode, vote_visibility, status,
                 proposed_by_user_id, proposed_at, expires_at, resolved_at,
                 meeting_reference, withdrawal_reason, via_delegation, delegation_id,
                 payload_target_user_id, payload_application_id, payload_sub_tier_slug,
                 payload_sub_tier_name, payload_sub_tier_rank, payload_sub_tier_applies_to,
                 payload_sub_tier_operation, payload_board_member_id, payload_delegation_type,
                 payload_delegated_to_role, payload_delegation_revoke_id, payload_reason)
             VALUES
                (:id, :bid, :dt, :th, :mo, :vv, :st,
                 :pby, :pat, :exp, :rat,
                 :mr, :wr, :vd, :did,
                 :ptu, :pai, :pss,
                 :psn, :psr, :psa,
                 :pso, :pbm, :pdt,
                 :pdr, :pdri, :prn)
             ON DUPLICATE KEY UPDATE
                status            = VALUES(status),
                resolved_at       = VALUES(resolved_at),
                withdrawal_reason = VALUES(withdrawal_reason)'
        );
        $stmt->execute([
            ':id'   => $d->id->value(),
            ':bid'  => $d->boardId->value(),
            ':dt'   => $d->decisionType->value,
            ':th'   => $d->threshold->value,
            ':mo'   => $d->mode->value,
            ':vv'   => $d->voteVisibility->value,
            ':st'   => $d->status->value,
            ':pby'  => $d->proposedByUserId->value(),
            ':pat'  => $d->proposedAt->format('Y-m-d H:i:s'),
            ':exp'  => $d->expiresAt->format('Y-m-d H:i:s'),
            ':rat'  => $d->resolvedAt?->format('Y-m-d H:i:s'),
            ':mr'   => $d->meetingReference,
            ':wr'   => $d->withdrawalReason,
            ':vd'   => $d->viaDelegation ? 1 : 0,
            ':did'  => $d->delegationId?->value(),
            ':ptu'  => $d->payloadTargetUserId?->value(),
            ':pai'  => $d->payloadApplicationId,
            ':pss'  => $d->payloadSubTierSlug,
            ':psn'  => $d->payloadSubTierName,
            ':psr'  => $d->payloadSubTierRank,
            ':psa'  => $d->payloadSubTierAppliesTo,
            ':pso'  => $d->payloadSubTierOperation?->value,
            ':pbm'  => $d->payloadBoardMemberId?->value(),
            ':pdt'  => $d->payloadDelegationType?->value,
            ':pdr'  => $d->payloadDelegatedToRole,
            ':pdri' => $d->payloadDelegationRevokeId?->value(),
            ':prn'  => $d->payloadReason,
        ]);
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): BoardDecision
    {
        $r = static function (string $k) use ($row): string {
            $v = $row[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt board_decisions.{$k}");
        };
        $s = static fn(string $k): ?string => is_string($row[$k] ?? null) ? $row[$k] : null;
        $i = static function (string $k) use ($row): ?int {
            $v = $row[$k] ?? null;
            if (is_int($v)) return $v;
            if (is_string($v) && ctype_digit($v)) return (int) $v;
            return null;
        };

        return new BoardDecision(
            id:               BoardDecisionId::fromString($r('id')),
            boardId:          BoardId::fromString($r('board_id')),
            decisionType:     BoardDecisionType::from($r('decision_type')),
            threshold:        BoardDecisionThreshold::from($r('threshold')),
            mode:             BoardDecisionMode::from($r('mode')),
            voteVisibility:   BoardDecisionVoteVisibility::from($r('vote_visibility')),
            status:           BoardDecisionStatus::from($r('status')),
            proposedByUserId: UserId::fromString($r('proposed_by_user_id')),
            proposedAt:       new \DateTimeImmutable($r('proposed_at')),
            expiresAt:        new \DateTimeImmutable($r('expires_at')),
            resolvedAt:       $s('resolved_at') !== null ? new \DateTimeImmutable((string) $s('resolved_at')) : null,
            meetingReference: $s('meeting_reference'),
            withdrawalReason: $s('withdrawal_reason'),
            viaDelegation:    $i('via_delegation') === 1,
            delegationId:     $s('delegation_id') !== null ? BoardDelegationId::fromString((string) $s('delegation_id')) : null,
            payloadTargetUserId:       $s('payload_target_user_id')  !== null ? UserId::fromString((string) $s('payload_target_user_id')) : null,
            payloadApplicationId:      $s('payload_application_id'),
            payloadSubTierSlug:        $s('payload_sub_tier_slug'),
            payloadSubTierName:        $s('payload_sub_tier_name'),
            payloadSubTierRank:        $i('payload_sub_tier_rank'),
            payloadSubTierAppliesTo:   $s('payload_sub_tier_applies_to'),
            payloadSubTierOperation:   $s('payload_sub_tier_operation') !== null ? BoardDecisionSubTierCrudOperation::from((string) $s('payload_sub_tier_operation')) : null,
            payloadBoardMemberId:      $s('payload_board_member_id') !== null ? BoardMemberId::fromString((string) $s('payload_board_member_id')) : null,
            payloadDelegationType:     $s('payload_delegation_type') !== null ? BoardDecisionType::from((string) $s('payload_delegation_type')) : null,
            payloadDelegatedToRole:    $s('payload_delegated_to_role'),
            payloadDelegationRevokeId: $s('payload_delegation_revoke_id') !== null ? BoardDelegationId::fromString((string) $s('payload_delegation_revoke_id')) : null,
            payloadReason:             $s('payload_reason'),
        );
    }
}
