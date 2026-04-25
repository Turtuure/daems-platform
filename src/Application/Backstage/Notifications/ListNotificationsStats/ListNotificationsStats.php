<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Notifications\ListNotificationsStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

/**
 * Assembles the 4-KPI strip for the unified Notifications inbox at /backstage
 * by orchestrating slices from 5 repositories:
 *
 *   - MemberApplication / SupporterApplication / ProjectProposal / ForumReport:
 *       notificationStatsForTenant + clearedDailyForTenant
 *   - AdminApplicationDismissal:
 *       dismissedAppIdsFor — count is subtracted from total pending to produce
 *       the actor-personalized "pending_you" KPI.
 *
 * KPIs:
 *   - pending_you      = max(0, total_pending - count(actor's dismissals));
 *                        sparkline = element-wise sum of incoming-volume series
 *                        across all 4 sources (same series used for pending_all)
 *   - pending_all      = total_pending across 4 sources;
 *                        sparkline shared with pending_you
 *   - cleared_30d      = sum across 4 sources of clearedDailyForTenant; value is
 *                        total clearings over the 30-day window, sparkline is
 *                        the element-wise sum of the 4 cleared series
 *   - oldest_pending_d = max(oldest_pending_age_days) across 4 sources; sparkline = []
 *
 * Admin-gated via ForbiddenException.
 */
final class ListNotificationsStats
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
        private readonly ProjectProposalRepositoryInterface $projectProposals,
        private readonly ForumReportRepositoryInterface $forumReports,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    ) {}

    public function execute(ListNotificationsStatsInput $input): ListNotificationsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }
        $tid = $input->tenantId;

        $m = $this->memberApps->notificationStatsForTenant($tid);
        $s = $this->supporterApps->notificationStatsForTenant($tid);
        $p = $this->projectProposals->notificationStatsForTenant($tid);
        $f = $this->forumReports->notificationStatsForTenant($tid);

        $totalPending = $m['pending_count']
                      + $s['pending_count']
                      + $p['pending_count']
                      + $f['pending_count'];

        $oldestDays = max(
            $m['oldest_pending_age_days'],
            $s['oldest_pending_age_days'],
            $p['oldest_pending_age_days'],
            $f['oldest_pending_age_days'],
        );

        // Shared incoming-volume sparkline (sum of newly-created rows across 4 sources).
        $incomingSparkline = self::sumSeries([
            $m['created_at_daily_30d'],
            $s['created_at_daily_30d'],
            $p['created_at_daily_30d'],
            $f['created_at_daily_30d'],
        ]);

        // Cleared = element-wise sum of clearedDailyForTenant across 4 sources.
        $clearedSeries = self::sumSeries([
            $this->memberApps->clearedDailyForTenant($tid),
            $this->supporterApps->clearedDailyForTenant($tid),
            $this->projectProposals->clearedDailyForTenant($tid),
            $this->forumReports->clearedDailyForTenant($tid),
        ]);
        $clearedTotal = 0;
        foreach ($clearedSeries as $point) {
            $clearedTotal += $point['value'];
        }

        // pending_you = pending_all - count of actor's dismissals (clamped to >= 0).
        $dismissedIds = $this->dismissals->dismissedAppIdsFor($input->acting->id);
        $pendingYou   = max(0, $totalPending - count($dismissedIds));

        return new ListNotificationsStatsOutput(stats: [
            'pending_you'      => ['value' => $pendingYou,    'sparkline' => $incomingSparkline],
            'pending_all'      => ['value' => $totalPending,  'sparkline' => $incomingSparkline],
            'cleared_30d'      => ['value' => $clearedTotal,  'sparkline' => $clearedSeries],
            'oldest_pending_d' => ['value' => $oldestDays,    'sparkline' => []],
        ]);
    }

    /**
     * Element-wise sum of N parallel daily series — all assumed to share the same
     * 30-day backward date sequence keyed by today.
     *
     * @param list<list<array{date: string, value: int}>> $series
     * @return list<array{date: string, value: int}>
     */
    private static function sumSeries(array $series): array
    {
        if (count($series) === 0) {
            return [];
        }
        $first = $series[0];
        $count = count($series);
        $out   = [];
        foreach ($first as $i => $point) {
            $value = $point['value'];
            for ($j = 1; $j < $count; $j++) {
                $value += $series[$j][$i]['value'] ?? 0;
            }
            $out[] = ['date' => $point['date'], 'value' => $value];
        }
        return $out;
    }
}
