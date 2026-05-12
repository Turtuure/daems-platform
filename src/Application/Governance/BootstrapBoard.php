<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardAlreadyBootstrapped;
use Daems\Domain\Governance\Exception\BoardCandidateNotFull;
use Daems\Domain\Governance\Exception\InvalidBootstrapRoster;
use Daems\Domain\User\UserId;

final class BootstrapBoard
{
    /** @param callable(UserId):array{membership_type:string, membership_status:string} $userLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private $userLookup,
    ) {}

    public function execute(BootstrapBoardInput $in): BoardId
    {
        if ($this->boards->findForTenant($in->tenantId) !== null) {
            throw new BoardAlreadyBootstrapped("tenant={$in->tenantId->value()}");
        }

        $n = count($in->members);
        if ($n < 1 || $n > 5) {
            throw new InvalidBootstrapRoster("Board must have 1–5 members; got {$n}");
        }
        $chairCount = 0;
        foreach ($in->members as $row) {
            if ($row['role'] === 'chair') $chairCount++;
        }
        if ($chairCount !== 1) {
            throw new InvalidBootstrapRoster("Exactly one chair required; got {$chairCount}");
        }

        // Eligibility + term-length validation.
        $lookup = $this->userLookup;
        foreach ($in->members as $row) {
            $uid = UserId::fromString($row['user_id']);
            $u   = $lookup($uid);
            if ($u['membership_type'] !== 'FULL' || $u['membership_status'] !== 'active') {
                throw new BoardCandidateNotFull("user={$row['user_id']} not FULL+active");
            }
            $ts = new \DateTimeImmutable($row['term_started_at']);
            $te = new \DateTimeImmutable($row['term_ends_at']);
            $years = (int) $ts->diff($te)->format('%y');
            if ($years < 1 || $years > 4) {
                throw new InvalidBootstrapRoster("Term length must be 1–4 years; got {$years} for user={$row['user_id']}");
            }
        }

        $board = new Board(
            id: BoardId::generate(),
            tenantId: $in->tenantId,
            bootstrappedByUserId: $in->gsaUserId,
            bootstrappedAt: $in->at,
            createdAt: $in->at,
        );
        $this->boards->save($board);

        foreach ($in->members as $row) {
            $this->members->save(new BoardMember(
                id: BoardMemberId::generate(),
                boardId: $board->id,
                userId: UserId::fromString($row['user_id']),
                role: BoardMemberRole::from($row['role']),
                termStartedAt: new \DateTimeImmutable($row['term_started_at']),
                termEndsAt:    new \DateTimeImmutable($row['term_ends_at']),
                termEndedAt: null,
                termEndedReason: null,
            ));
        }

        return $board->id;
    }
}
