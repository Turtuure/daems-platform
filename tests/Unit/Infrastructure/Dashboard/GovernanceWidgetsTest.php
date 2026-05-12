<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\CoreWidgets\DelegationsActiveKpiWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\EligibleForFullMembershipWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\OpenExpulsionsKpiWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\PendingDecisionsForMeKpiWidget;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionVoteRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository;
use PHPUnit\Framework\TestCase;

final class GovernanceWidgetsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // PendingDecisionsForMeKpiWidget
    // -------------------------------------------------------------------------

    public function test_pending_decisions_for_me_metadata(): void
    {
        $w = new PendingDecisionsForMeKpiWidget(
            new InMemoryBoardRepository(),
            new InMemoryBoardDecisionRepository(),
            new InMemoryBoardMemberRepository(),
            new InMemoryBoardDecisionVoteRepository(),
        );
        self::assertSame('governance.pending_decisions_for_me_kpi', $w->id());
        self::assertSame(WidgetCategory::Numbers, $w->category());
        self::assertSame(1, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_pending_decisions_for_me_counts_unvoted_decisions(): void
    {
        $tenantId = TenantId::generate();
        $userId   = UserId::generate();
        $boardId  = BoardId::generate();
        $memberId = BoardMemberId::generate();
        $now      = new \DateTimeImmutable();

        $boardRepo    = new InMemoryBoardRepository();
        $decisionRepo = new InMemoryBoardDecisionRepository();
        $memberRepo   = new InMemoryBoardMemberRepository();
        $voteRepo     = new InMemoryBoardDecisionVoteRepository();

        // Bootstrap board
        $boardRepo->save(new Board(
            id:                  $boardId,
            tenantId:            $tenantId,
            bootstrappedByUserId: $userId,
            bootstrappedAt:      $now,
            createdAt:           $now,
        ));

        // Board member (active: started 1 year ago, ends 1 year from now)
        $memberRepo->save(new BoardMember(
            id:              $memberId,
            boardId:         $boardId,
            userId:          $userId,
            role:            BoardMemberRole::Chair,
            termStartedAt:   $now->modify('-1 year'),
            termEndsAt:      $now->modify('+1 year'),
            termEndedAt:     null,
            termEndedReason: null,
        ));

        // 3 pending decisions — add the same fields the constructor requires
        $proposerId = UserId::generate();
        for ($i = 0; $i < 3; $i++) {
            $decisionRepo->save(new BoardDecision(
                id:              BoardDecisionId::generate(),
                boardId:         $boardId,
                decisionType:    BoardDecisionType::ApproveBasic,
                threshold:       BoardDecisionThreshold::Majority,
                mode:            BoardDecisionMode::Sync,
                voteVisibility:  BoardDecisionVoteVisibility::Visible,
                status:          BoardDecisionStatus::Pending,
                proposedByUserId: $proposerId,
                proposedAt:      $now,
                expiresAt:       $now->modify('+7 days'),
                resolvedAt:      null,
                meetingReference: null,
                withdrawalReason: null,
                viaDelegation:   false,
                delegationId:    null,
            ));
        }

        $user = $this->fakeUserWithId($userId);
        $w    = new PendingDecisionsForMeKpiWidget($boardRepo, $decisionRepo, $memberRepo, $voteRepo);
        $data = $w->data($tenantId, $user);

        self::assertSame(3, $data['count']);
    }

    public function test_pending_decisions_for_me_returns_zero_when_no_board(): void
    {
        $w = new PendingDecisionsForMeKpiWidget(
            new InMemoryBoardRepository(),
            new InMemoryBoardDecisionRepository(),
            new InMemoryBoardMemberRepository(),
            new InMemoryBoardDecisionVoteRepository(),
        );
        $data = $w->data(TenantId::generate(), $this->fakeUserWithId(UserId::generate()));
        self::assertSame(0, $data['count']);
    }

    // -------------------------------------------------------------------------
    // OpenExpulsionsKpiWidget
    // -------------------------------------------------------------------------

    public function test_open_expulsions_metadata(): void
    {
        $w = new OpenExpulsionsKpiWidget(new InMemoryMemberExpulsionRepository());
        self::assertSame('governance.open_expulsions_kpi', $w->id());
        self::assertSame(WidgetCategory::Numbers, $w->category());
        self::assertSame(1, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_open_expulsions_counts_hearing_and_awaiting_vote(): void
    {
        $tenantId = TenantId::generate();
        $repo     = new InMemoryMemberExpulsionRepository();
        $now      = new \DateTimeImmutable();

        $statuses = [
            MemberExpulsionStatus::Hearing,
            MemberExpulsionStatus::Hearing,
            MemberExpulsionStatus::AwaitingVote,
            MemberExpulsionStatus::Expelled,   // should NOT be counted
        ];

        foreach ($statuses as $status) {
            $repo->save(new MemberExpulsion(
                id:                  MemberExpulsionId::generate(),
                tenantId:            $tenantId,
                targetUserId:        UserId::generate(),
                proposedByUserId:    UserId::generate(),
                reason:              'test',
                hearingDeadlineAt:   $now->modify('+7 days'),
                statementText:       null,
                statementReceivedAt: null,
                decisionId:          null,
                decidedAt:           null,
                expelledAt:          null,
                appealFiledAt:       null,
                appealText:          null,
                status:              $status,
                createdAt:           $now,
            ));
        }

        $w    = new OpenExpulsionsKpiWidget($repo);
        $data = $w->data($tenantId);

        self::assertSame(3, $data['count']);
        self::assertSame(2, $data['hearing']);
        self::assertSame(1, $data['awaiting_vote']);
    }

    // -------------------------------------------------------------------------
    // DelegationsActiveKpiWidget
    // -------------------------------------------------------------------------

    public function test_delegations_active_metadata(): void
    {
        $w = new DelegationsActiveKpiWidget(new InMemoryBoardDelegationRepository());
        self::assertSame('governance.delegations_active_kpi', $w->id());
        self::assertSame(WidgetCategory::Numbers, $w->category());
        self::assertSame(1, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_delegations_active_returns_zero_when_empty(): void
    {
        $w    = new DelegationsActiveKpiWidget(new InMemoryBoardDelegationRepository());
        $data = $w->data(TenantId::generate());
        self::assertSame(0, $data['count']);
    }

    // -------------------------------------------------------------------------
    // EligibleForFullMembershipWidget
    // -------------------------------------------------------------------------

    public function test_eligible_for_full_metadata(): void
    {
        $w = new EligibleForFullMembershipWidget(fn($t, $at) => []);
        self::assertSame('governance.eligible_for_full_membership', $w->id());
        self::assertSame(WidgetCategory::Lists, $w->category());
        self::assertSame(2, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_eligible_for_full_returns_rows_from_lookup(): void
    {
        $tenantId = TenantId::generate();
        $rows     = [
            ['id' => 'u1', 'name' => 'Alice', 'member_number' => 'M001', 'membership_started_at' => '2024-01-01', 'months_since_join' => 16],
            ['id' => 'u2', 'name' => 'Bob',   'member_number' => null,   'membership_started_at' => '2024-02-01', 'months_since_join' => 15],
        ];

        $w    = new EligibleForFullMembershipWidget(fn($t, $at) => $rows);
        $data = $w->data($tenantId);

        self::assertSame(2, $data['count']);
        self::assertCount(2, $data['users']);
        self::assertSame('Alice', $data['users'][0]['name']);
    }

    public function test_eligible_for_full_empty_lookup(): void
    {
        $w    = new EligibleForFullMembershipWidget(fn($t, $at) => []);
        $data = $w->data(TenantId::generate());
        self::assertSame(0, $data['count']);
        self::assertSame([], $data['users']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakeUserWithId(UserId $userId): \Daems\Domain\User\User
    {
        return new \Daems\Domain\User\User(
            id:           $userId,
            name:         'Test User',
            email:        'test@example.com',
            passwordHash: null,
            dateOfBirth:  null,
        );
    }
}
