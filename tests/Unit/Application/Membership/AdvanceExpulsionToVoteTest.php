<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\AdvanceExpulsionToVote;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Exception\ExpulsionAlreadyAdvanced;
use Daems\Domain\Membership\Exception\ExpulsionHearingNotElapsed;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class AdvanceExpulsionToVoteTest extends TestCase
{
    /** @return array{uc:AdvanceExpulsionToVote, boards:InMemoryBoardRepository, members:InMemoryBoardMemberRepository, expulsions:InMemoryMemberExpulsionRepository, decisions:InMemoryBoardDecisionRepository, tenant:TenantId, board:Board, chair:UserId, expulsionId:MemberExpulsionId} */
    private function harness(?\DateTimeImmutable $statementReceived = null, ?\DateTimeImmutable $hearingDeadline = null): array
    {
        $boards = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $expulsions = new InMemoryMemberExpulsionRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();

        $tenant = TenantId::generate();
        $chair  = UserId::generate();
        $target = UserId::generate();
        $proposer = UserId::generate();
        $board = new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $chair,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        );
        $boards->save($board);
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));
        $members->save(new BoardMember(
            id: BoardMemberId::generate(),
            boardId: $board->id, userId: $chair, role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));

        $expulsionId = MemberExpulsionId::generate();
        $expulsions->save(new MemberExpulsion(
            id: $expulsionId, tenantId: $tenant,
            targetUserId: $target, proposedByUserId: $proposer,
            reason: 'inactive',
            hearingDeadlineAt: $hearingDeadline ?? new \DateTimeImmutable('2026-06-01'),
            statementText: $statementReceived !== null ? 'I disagree' : null,
            statementReceivedAt: $statementReceived,
            decisionId: null, decidedAt: null, expelledAt: null,
            appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $uc = new AdvanceExpulsionToVote($boards, $members, $expulsions, $decisions, $settings);
        return compact('uc', 'boards', 'members', 'expulsions', 'decisions', 'tenant', 'board', 'chair', 'expulsionId');
    }

    public function test_rejects_when_not_chair(): void
    {
        $h = $this->harness(statementReceived: new \DateTimeImmutable('2026-05-15'));
        $this->expectException(NotABoardMember::class);
        $h['uc']->execute($h['expulsionId'], UserId::generate(), new \DateTimeImmutable('2026-05-20'), 'Kokous 1');
    }

    public function test_rejects_when_hearing_not_elapsed_and_no_statement(): void
    {
        $h = $this->harness();
        $this->expectException(ExpulsionHearingNotElapsed::class);
        $h['uc']->execute($h['expulsionId'], $h['chair'], new \DateTimeImmutable('2026-05-15'), 'Kokous 1');
    }

    public function test_advances_when_statement_received(): void
    {
        $h = $this->harness(statementReceived: new \DateTimeImmutable('2026-05-15'));
        $decisionId = $h['uc']->execute($h['expulsionId'], $h['chair'], new \DateTimeImmutable('2026-05-20 10:00:00'), 'Kokous 2026-05-20');

        $d = $h['decisions']->find($decisionId);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::Expel, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Unanimous, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame('Kokous 2026-05-20', $d->meetingReference);

        $e = $h['expulsions']->find($h['expulsionId']);
        $this->assertNotNull($e);
        $this->assertSame(MemberExpulsionStatus::AwaitingVote, $e->status);
        $this->assertSame($decisionId->value(), $e->decisionId?->value());
    }

    public function test_rejects_when_already_advanced(): void
    {
        $h = $this->harness(statementReceived: new \DateTimeImmutable('2026-05-15'));
        $h['uc']->execute($h['expulsionId'], $h['chair'], new \DateTimeImmutable('2026-05-20'), 'Kokous 1');

        $this->expectException(ExpulsionAlreadyAdvanced::class);
        $h['uc']->execute($h['expulsionId'], $h['chair'], new \DateTimeImmutable('2026-05-20'), 'Kokous 2');
    }
}
