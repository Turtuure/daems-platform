<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function test_board_member_role_values(): void
    {
        $this->assertSame('chair',  BoardMemberRole::Chair->value);
        $this->assertSame('member', BoardMemberRole::Member->value);
    }

    public function test_term_ended_reason_values(): void
    {
        $this->assertSame('resigned',         BoardMemberTermEndedReason::Resigned->value);
        $this->assertSame('removed',          BoardMemberTermEndedReason::Removed->value);
        $this->assertSame('lost_full_status', BoardMemberTermEndedReason::LostFullStatus->value);
        $this->assertSame('term_expired',     BoardMemberTermEndedReason::TermExpired->value);
    }

    public function test_decision_type_values(): void
    {
        $expected = [
            'approve_basic','invite_full','expel','award_subtier','revoke_subtier',
            'subtier_crud','remove_board_member','delegate_authority','revoke_delegation',
            'annual_fee_schedule',
        ];
        $actual = array_map(fn(BoardDecisionType $c) => $c->value, BoardDecisionType::cases());
        $this->assertSame($expected, $actual);
    }

    public function test_decision_type_is_delegatable(): void
    {
        $this->assertTrue(BoardDecisionType::ApproveBasic->isDelegatable());
        $this->assertTrue(BoardDecisionType::InviteFull->isDelegatable());
        $this->assertTrue(BoardDecisionType::AwardSubTier->isDelegatable());
        $this->assertFalse(BoardDecisionType::Expel->isDelegatable());
        $this->assertFalse(BoardDecisionType::RevokeSubTier->isDelegatable());
        $this->assertFalse(BoardDecisionType::SubTierCrud->isDelegatable());
        $this->assertFalse(BoardDecisionType::RemoveBoardMember->isDelegatable());
        $this->assertFalse(BoardDecisionType::DelegateAuthority->isDelegatable());
        $this->assertFalse(BoardDecisionType::RevokeDelegation->isDelegatable());
        $this->assertFalse(BoardDecisionType::AnnualFeeSchedule->isDelegatable());
    }

    public function test_threshold_mode_status_visibility(): void
    {
        $this->assertSame(['unanimous','majority'],
            array_map(fn(BoardDecisionThreshold $c) => $c->value, BoardDecisionThreshold::cases()));
        $this->assertSame(['async','sync'],
            array_map(fn(BoardDecisionMode $c) => $c->value, BoardDecisionMode::cases()));
        $this->assertSame(['pending','passed','rejected','expired','withdrawn'],
            array_map(fn(BoardDecisionStatus $c) => $c->value, BoardDecisionStatus::cases()));
        $this->assertSame(['visible','anonymous'],
            array_map(fn(BoardDecisionVoteVisibility $c) => $c->value, BoardDecisionVoteVisibility::cases()));
        $this->assertSame(['create','update','delete'],
            array_map(fn(BoardDecisionSubTierCrudOperation $c) => $c->value, BoardDecisionSubTierCrudOperation::cases()));
        $this->assertSame(['yes','no','abstain'],
            array_map(fn(BoardDecisionVoteValue $c) => $c->value, BoardDecisionVoteValue::cases()));
    }

    public function test_decision_status_is_resolved(): void
    {
        $this->assertFalse(BoardDecisionStatus::Pending->isResolved());
        $this->assertTrue(BoardDecisionStatus::Passed->isResolved());
        $this->assertTrue(BoardDecisionStatus::Rejected->isResolved());
        $this->assertTrue(BoardDecisionStatus::Expired->isResolved());
        $this->assertTrue(BoardDecisionStatus::Withdrawn->isResolved());
    }
}
