<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BoardDecisionResolutionService;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRole;
use PHPUnit\Framework\TestCase;

final class BoardDecisionResolutionServiceTest extends TestCase
{
    private BoardDecisionResolutionService $svc;
    protected function setUp(): void { $this->svc = new BoardDecisionResolutionService(); }

    /**
     * Active board roster passed as list of [role, vote|null].
     * vote=null means the member has not yet voted.
     *
     * @param list<array{role:BoardMemberRole, vote:?BoardDecisionVoteValue}> $roster
     */
    private function resolve(BoardDecisionThreshold $t, array $roster): BoardDecisionStatus
    {
        $active = count($roster);
        $tally  = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        $chairVoted = false;
        foreach ($roster as $m) {
            if ($m['vote'] === null) continue;
            $tally[$m['vote']->value]++;
            if ($m['role'] === BoardMemberRole::Chair) $chairVoted = true;
        }
        return $this->svc->resolve(
            threshold:    $t,
            activeCount:  $active,
            yes:          $tally['yes'],
            no:           $tally['no'],
            abstain:      $tally['abstain'],
            chairVoted:   $chairVoted,
        );
    }

    public function test_unanimous_passes_when_all_voted_yes(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
        ];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_rejects_on_any_no(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_rejects_on_any_abstain(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Abstain],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_pending_when_not_everyone_voted_yet(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_majority_passes_when_strict_majority_yes_and_quorum_met_with_chair(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_pending_when_no_chair_vote_yet(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_rejects_when_max_possible_yes_below_threshold(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_pending_when_yes_short_but_more_could_come(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_size_one_passes_with_chair_yes(): void
    {
        $r = [['role' => BoardMemberRole::Chair, 'vote' => BoardDecisionVoteValue::Yes]];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_zero_active_count_returns_pending(): void
    {
        $this->assertSame(BoardDecisionStatus::Pending,
            $this->svc->resolve(BoardDecisionThreshold::Unanimous, 0, 0, 0, 0, false));
        $this->assertSame(BoardDecisionStatus::Pending,
            $this->svc->resolve(BoardDecisionThreshold::Majority, 0, 0, 0, 0, false));
    }
}
