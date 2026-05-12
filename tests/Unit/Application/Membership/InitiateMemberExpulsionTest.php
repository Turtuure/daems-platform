<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\InitiateMemberExpulsion;
use Daems\Application\Membership\InitiateMemberExpulsionInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class InitiateMemberExpulsionTest extends TestCase
{
    private function harness(bool $proposerIsActiveBoardMember = true): array
    {
        $boards = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $expulsions = new InMemoryMemberExpulsionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $target = UserId::generate();
        $board = new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        );
        $boards->save($board);
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        if ($proposerIsActiveBoardMember) {
            $members->save(new BoardMember(
                id: BoardMemberId::generate(),
                boardId: $board->id,
                userId: $proposer,
                role: BoardMemberRole::Member,
                termStartedAt: new \DateTimeImmutable('2026-01-01'),
                termEndsAt:    new \DateTimeImmutable('2028-01-01'),
                termEndedAt: null,
                termEndedReason: null,
            ));
        }

        $uc = new InitiateMemberExpulsion($boards, $members, $expulsions, $settings);
        return compact('uc', 'expulsions', 'tenant', 'proposer', 'target');
    }

    public function test_rejects_when_proposer_not_board_member(): void
    {
        $h = $this->harness(proposerIsActiveBoardMember: false);
        $this->expectException(NotABoardMember::class);
        $h['uc']->execute(new InitiateMemberExpulsionInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            proposedByUserId: $h['proposer'],
            reason: 'reason',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_rejects_empty_reason(): void
    {
        $h = $this->harness();
        $this->expectException(\InvalidArgumentException::class);
        $h['uc']->execute(new InitiateMemberExpulsionInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            proposedByUserId: $h['proposer'],
            reason: '',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_expulsion_in_hearing_status(): void
    {
        $h = $this->harness();
        $id = $h['uc']->execute(new InitiateMemberExpulsionInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            proposedByUserId: $h['proposer'],
            reason: 'Inactive 6 months',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $e = $h['expulsions']->find($id);
        $this->assertNotNull($e);
        $this->assertSame(MemberExpulsionStatus::Hearing, $e->status);
        $this->assertSame('Inactive 6 months', $e->reason);
        $this->assertSame($h['target']->value(), $e->targetUserId->value());
        // 14 days hearing deadline from at
        $this->assertSame('2026-06-03 10:00:00', $e->hearingDeadlineAt->format('Y-m-d H:i:s'));
        $this->assertNull($e->decisionId);
        $this->assertNull($e->statementText);
    }
}
