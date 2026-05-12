<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeRemoveBoardMember;
use Daems\Application\Governance\Propose\ProposeRemoveBoardMemberInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\LastBoardMemberCannotBeRemoved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeRemoveBoardMemberTest extends TestCase
{
    /** @return array{uc:ProposeRemoveBoardMember, decisions:InMemoryBoardDecisionRepository, members:InMemoryBoardMemberRepository, tenant:TenantId, board:Board, proposer:UserId} */
    private function harness(): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $members = new InMemoryBoardMemberRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $board = new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        );
        $boards->save($board);
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeRemoveBoardMember($boards, $decisions, $settings, $members);
        return compact('uc', 'decisions', 'members', 'tenant', 'board', 'proposer');
    }

    public function test_rejects_when_member_not_found_on_board(): void
    {
        $h = $this->harness();
        $this->expectException(NotABoardMember::class);
        $h['uc']->execute(new ProposeRemoveBoardMemberInput(
            tenantId: $h['tenant'],
            targetBoardMemberId: BoardMemberId::generate(),
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'reason',
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_rejects_when_last_active_member(): void
    {
        $h = $this->harness();
        $chairId = BoardMemberId::generate();
        $h['members']->save(new BoardMember(
            id: $chairId,
            boardId: $h['board']->id,
            userId: UserId::generate(),
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null,
            termEndedReason: null,
        ));
        $this->expectException(LastBoardMemberCannotBeRemoved::class);
        $h['uc']->execute(new ProposeRemoveBoardMemberInput(
            tenantId: $h['tenant'],
            targetBoardMemberId: $chairId,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'reason',
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_unanimous_sync_decision(): void
    {
        $h = $this->harness();
        $chairId = BoardMemberId::generate();
        $member2Id = BoardMemberId::generate();
        $h['members']->save(new BoardMember(
            id: $chairId, boardId: $h['board']->id, userId: UserId::generate(),
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));
        $h['members']->save(new BoardMember(
            id: $member2Id, boardId: $h['board']->id, userId: UserId::generate(),
            role: BoardMemberRole::Member,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));

        $id = $h['uc']->execute(new ProposeRemoveBoardMemberInput(
            tenantId: $h['tenant'],
            targetBoardMemberId: $member2Id,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'Inactive',
            meetingReference: 'Kokous 2026-05-20',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $d = $h['decisions']->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::RemoveBoardMember, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Unanimous, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame($member2Id->value(), $d->payloadBoardMemberId?->value());
    }
}
