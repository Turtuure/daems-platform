<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;

/**
 * Pure decision-resolution math. Inputs are aggregate counts of active board
 * members + their cast votes + whether the chair voted. No PDO, no domain
 * objects — just numbers in, status out. Easy to test exhaustively.
 *
 * Logic per spec § "Decision-lifecycle (state machine)":
 *
 * Unanimous:
 *   no > 0 or abstain > 0  → Rejected
 *   yes == active          → Passed
 *   else                   → Pending
 *
 * Majority:
 *   strict_majority = floor(active/2) + 1
 *   max_possible_yes = (active - voted) + yes
 *   if quorum_met AND yes >= strict_majority  → Passed
 *   if max_possible_yes < strict_majority      → Rejected
 *   else                                        → Pending
 *
 * Quorum (Majority only): chair has voted AND voted >= ceil(active/2).
 * For async + unanimous, every active member must vote yes — quorum implicit.
 */
final class BoardDecisionResolutionService
{
    public function resolve(
        BoardDecisionThreshold $threshold,
        int $activeCount,
        int $yes,
        int $no,
        int $abstain,
        bool $chairVoted,
    ): BoardDecisionStatus {
        if ($activeCount <= 0) {
            return BoardDecisionStatus::Pending;
        }
        $voted = $yes + $no + $abstain;

        if ($threshold === BoardDecisionThreshold::Unanimous) {
            if ($no > 0 || $abstain > 0)  return BoardDecisionStatus::Rejected;
            if ($yes === $activeCount)    return BoardDecisionStatus::Passed;
            return BoardDecisionStatus::Pending;
        }

        // Majority
        $strictMajority  = intdiv($activeCount, 2) + 1;
        $maxPossibleYes  = ($activeCount - $voted) + $yes;
        $quorumNeeded    = (int) ceil($activeCount / 2);
        $quorumMet       = $chairVoted && $voted >= $quorumNeeded;

        if ($quorumMet && $yes >= $strictMajority) {
            return BoardDecisionStatus::Passed;
        }
        if ($maxPossibleYes < $strictMajority) {
            return BoardDecisionStatus::Rejected;
        }
        return BoardDecisionStatus::Pending;
    }
}
