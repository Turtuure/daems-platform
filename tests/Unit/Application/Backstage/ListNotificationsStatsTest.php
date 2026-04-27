<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\ActingUserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListNotificationsStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';

    public function test_pending_you_subtracts_dismissed_count_pending_all_does_not(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $usecase = new ListNotificationsStats(
            memberApps:       $this->stubMemberRepo(pendingCount: 3, oldestDays: 5, incomingDailyConst: 1, clearedDailyConst: 0),
            supporterApps:    $this->stubSupporterRepo(pendingCount: 1, oldestDays: 2, incomingDailyConst: 0, clearedDailyConst: 0),
            projectProposals: $this->stubProposalRepo(pendingCount: 2, oldestDays: 7, incomingDailyConst: 2, clearedDailyConst: 0),
            forumReports:     $this->stubForumReportRepo(pendingCount: 1, oldestDays: 4, incomingDailyConst: 1, clearedDailyConst: 0),
            dismissals:       $this->stubDismissalRepoForActor(self::ADMIN_ID, dismissedIds: ['m1', 'p2']),
        );

        $out = $usecase->execute(new ListNotificationsStatsInput(acting: $admin, tenantId: $tenantId));

        // pending_all = 3 + 1 + 2 + 1 = 7; pending_you = 7 - 2 = 5.
        self::assertSame(7, $out->stats['pending_all']['value']);
        self::assertSame(5, $out->stats['pending_you']['value']);

        // oldest_pending_d = max(5, 2, 7, 4) = 7.
        self::assertSame(7, $out->stats['oldest_pending_d']['value']);
        self::assertSame([], $out->stats['oldest_pending_d']['sparkline']);

        // pending_all + pending_you share the incoming-volume sparkline (sum across 4 sources).
        self::assertCount(30, $out->stats['pending_you']['sparkline']);
        self::assertCount(30, $out->stats['pending_all']['sparkline']);
        // 1 + 0 + 2 + 1 = 4 per day on every day.
        foreach ($out->stats['pending_all']['sparkline'] as $point) {
            self::assertSame(4, $point['value']);
        }
        foreach ($out->stats['pending_you']['sparkline'] as $point) {
            self::assertSame(4, $point['value']);
        }
    }

    public function test_pending_you_clamped_to_zero_when_dismissals_exceed_pending(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $usecase = new ListNotificationsStats(
            memberApps:       $this->stubMemberRepo(pendingCount: 1, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            supporterApps:    $this->stubSupporterRepo(pendingCount: 1, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            projectProposals: $this->stubProposalRepo(pendingCount: 1, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            forumReports:     $this->stubForumReportRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            // 10 dismissals but total pending = 3.
            dismissals: $this->stubDismissalRepoForActor(self::ADMIN_ID, dismissedIds: array_fill(0, 10, 'x')),
        );

        $out = $usecase->execute(new ListNotificationsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(3, $out->stats['pending_all']['value']);
        self::assertSame(0, $out->stats['pending_you']['value']);
    }

    public function test_cleared_30d_sums_across_4_sources(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        // Each source contributes a constant per-day count for cleared. Total per day = 1+2+3+4 = 10.
        $usecase = new ListNotificationsStats(
            memberApps:       $this->stubMemberRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 1),
            supporterApps:    $this->stubSupporterRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 2),
            projectProposals: $this->stubProposalRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 3),
            forumReports:     $this->stubForumReportRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 4),
            dismissals:       $this->stubDismissalRepoForActor(self::ADMIN_ID, dismissedIds: []),
        );

        $out = $usecase->execute(new ListNotificationsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertCount(30, $out->stats['cleared_30d']['sparkline']);
        // Element-wise summed sparkline is constant 10/day.
        foreach ($out->stats['cleared_30d']['sparkline'] as $point) {
            self::assertArrayHasKey('date', $point);
            self::assertSame(10, $point['value']);
        }
        // Total = 30 days * 10 = 300.
        self::assertSame(300, $out->stats['cleared_30d']['value']);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $usecase = new ListNotificationsStats(
            memberApps:       $this->stubMemberRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            supporterApps:    $this->stubSupporterRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            projectProposals: $this->stubProposalRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            forumReports:     $this->stubForumReportRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            dismissals:       $this->stubDismissalRepoForActor(self::USER_ID, dismissedIds: []),
        );

        $usecase->execute(new ListNotificationsStatsInput(acting: $member, tenantId: $tenantId));
    }

    public function test_zero_state(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $usecase = new ListNotificationsStats(
            memberApps:       $this->stubMemberRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            supporterApps:    $this->stubSupporterRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            projectProposals: $this->stubProposalRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            forumReports:     $this->stubForumReportRepo(pendingCount: 0, oldestDays: 0, incomingDailyConst: 0, clearedDailyConst: 0),
            dismissals:       $this->stubDismissalRepoForActor(self::ADMIN_ID, dismissedIds: []),
        );

        $out = $usecase->execute(new ListNotificationsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(0, $out->stats['pending_you']['value']);
        self::assertSame(0, $out->stats['pending_all']['value']);
        self::assertSame(0, $out->stats['cleared_30d']['value']);
        self::assertSame(0, $out->stats['oldest_pending_d']['value']);

        // pending sparklines remain a 30-entry zero series; cleared sparkline same.
        self::assertCount(30, $out->stats['pending_all']['sparkline']);
        self::assertCount(30, $out->stats['cleared_30d']['sparkline']);
        self::assertSame([], $out->stats['oldest_pending_d']['sparkline']);
    }

    /** @return list<array{date: string, value: int}> */
    private static function constSeries(int $value): array
    {
        $today = new DateTimeImmutable('today');
        $out   = [];
        for ($i = 29; $i >= 0; $i--) {
            $out[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => $value,
            ];
        }
        return $out;
    }

    private function stubMemberRepo(
        int $pendingCount,
        int $oldestDays,
        int $incomingDailyConst,
        int $clearedDailyConst,
    ): MemberApplicationRepositoryInterface {
        return new class($pendingCount, $oldestDays, $incomingDailyConst, $clearedDailyConst) implements MemberApplicationRepositoryInterface {
            public function __construct(
                private readonly int $pendingCount,
                private readonly int $oldestDays,
                private readonly int $incomingDailyConst,
                private readonly int $clearedDailyConst,
            ) {}

            public function save(MemberApplication $application): void {}

            public function listPendingForTenant(TenantId $tenantId, int $limit): array
            {
                return [];
            }

            public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array
            {
                return [];
            }

            public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
            {
                return null;
            }

            public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array
            {
                return null;
            }

            public function recordDecision(
                string $id,
                TenantId $tenantId,
                string $decision,
                UserId $decidedBy,
                ?string $note,
                DateTimeImmutable $decidedAt,
            ): void {}

            public function statsForTenant(TenantId $tenantId): array
            {
                $spark = ListNotificationsStatsTest::constSeriesPublic(0);
                return [
                    'pending'             => ['value' => 0, 'sparkline' => $spark],
                    'approved_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'rejected_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'decided_count'       => 0,
                    'decided_total_hours' => 0,
                ];
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                return [
                    'pending_count'           => $this->pendingCount,
                    'created_at_daily_30d'    => ListNotificationsStatsTest::constSeriesPublic($this->incomingDailyConst),
                    'oldest_pending_age_days' => $this->oldestDays,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                return ListNotificationsStatsTest::constSeriesPublic($this->clearedDailyConst);
            }
        };
    }

    private function stubSupporterRepo(
        int $pendingCount,
        int $oldestDays,
        int $incomingDailyConst,
        int $clearedDailyConst,
    ): SupporterApplicationRepositoryInterface {
        return new class($pendingCount, $oldestDays, $incomingDailyConst, $clearedDailyConst) implements SupporterApplicationRepositoryInterface {
            public function __construct(
                private readonly int $pendingCount,
                private readonly int $oldestDays,
                private readonly int $incomingDailyConst,
                private readonly int $clearedDailyConst,
            ) {}

            public function save(SupporterApplication $application): void {}

            public function listPendingForTenant(TenantId $tenantId, int $limit): array
            {
                return [];
            }

            public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array
            {
                return [];
            }

            public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication
            {
                return null;
            }

            public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array
            {
                return null;
            }

            public function recordDecision(
                string $id,
                TenantId $tenantId,
                string $decision,
                UserId $decidedBy,
                ?string $note,
                DateTimeImmutable $decidedAt,
            ): void {}

            public function statsForTenant(TenantId $tenantId): array
            {
                $spark = ListNotificationsStatsTest::constSeriesPublic(0);
                return [
                    'pending'             => ['value' => 0, 'sparkline' => $spark],
                    'approved_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'rejected_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'decided_count'       => 0,
                    'decided_total_hours' => 0,
                ];
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                return [
                    'pending_count'           => $this->pendingCount,
                    'created_at_daily_30d'    => ListNotificationsStatsTest::constSeriesPublic($this->incomingDailyConst),
                    'oldest_pending_age_days' => $this->oldestDays,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                return ListNotificationsStatsTest::constSeriesPublic($this->clearedDailyConst);
            }
        };
    }

    private function stubProposalRepo(
        int $pendingCount,
        int $oldestDays,
        int $incomingDailyConst,
        int $clearedDailyConst,
    ): ProjectProposalRepositoryInterface {
        return new class($pendingCount, $oldestDays, $incomingDailyConst, $clearedDailyConst) implements ProjectProposalRepositoryInterface {
            public function __construct(
                private readonly int $pendingCount,
                private readonly int $oldestDays,
                private readonly int $incomingDailyConst,
                private readonly int $clearedDailyConst,
            ) {}

            public function save(ProjectProposal $proposal): void {}

            public function listPendingForTenant(TenantId $tenantId): array
            {
                return [];
            }

            public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal
            {
                return null;
            }

            public function recordDecision(
                string $id,
                TenantId $tenantId,
                string $decision,
                string $decidedBy,
                ?string $note,
                DateTimeImmutable $now,
            ): void {}

            public function pendingStatsForTenant(TenantId $tenantId): array
            {
                return ['value' => 0, 'sparkline' => ListNotificationsStatsTest::constSeriesPublic(0)];
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                return [
                    'pending_count'           => $this->pendingCount,
                    'created_at_daily_30d'    => ListNotificationsStatsTest::constSeriesPublic($this->incomingDailyConst),
                    'oldest_pending_age_days' => $this->oldestDays,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                return ListNotificationsStatsTest::constSeriesPublic($this->clearedDailyConst);
            }
        };
    }

    private function stubForumReportRepo(
        int $pendingCount,
        int $oldestDays,
        int $incomingDailyConst,
        int $clearedDailyConst,
    ): ForumReportRepositoryInterface {
        return new class($pendingCount, $oldestDays, $incomingDailyConst, $clearedDailyConst) implements ForumReportRepositoryInterface {
            public function __construct(
                private readonly int $pendingCount,
                private readonly int $oldestDays,
                private readonly int $incomingDailyConst,
                private readonly int $clearedDailyConst,
            ) {}

            public function upsert(ForumReport $report): void {}

            public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport
            {
                return null;
            }

            public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array
            {
                return [];
            }

            public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array
            {
                return [];
            }

            public function resolveAllForTarget(
                string $targetType,
                string $targetId,
                TenantId $tenantId,
                string $resolutionAction,
                string $resolvedBy,
                ?string $note,
                DateTimeImmutable $now,
            ): void {}

            public function dismissAllForTarget(
                string $targetType,
                string $targetId,
                TenantId $tenantId,
                string $resolvedBy,
                ?string $note,
                DateTimeImmutable $now,
            ): void {}

            public function countOpenForTenant(TenantId $tenantId): int
            {
                return 0;
            }

            public function countOpenReportsForTenant(TenantId $tenantId): int
            {
                return 0;
            }

            public function dailyNewReportsForTenant(TenantId $tenantId): array
            {
                return ListNotificationsStatsTest::constSeriesPublic(0);
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                return [
                    'pending_count'           => $this->pendingCount,
                    'created_at_daily_30d'    => ListNotificationsStatsTest::constSeriesPublic($this->incomingDailyConst),
                    'oldest_pending_age_days' => $this->oldestDays,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                return ListNotificationsStatsTest::constSeriesPublic($this->clearedDailyConst);
            }
        };
    }

    /**
     * @param list<string> $dismissedIds
     */
    private function stubDismissalRepoForActor(string $expectedActorId, array $dismissedIds): AdminApplicationDismissalRepositoryInterface
    {
        return new class($expectedActorId, $dismissedIds) implements AdminApplicationDismissalRepositoryInterface {
            /** @param list<string> $dismissedIds */
            public function __construct(
                private readonly string $expectedActorId,
                private readonly array $dismissedIds,
            ) {}

            public function save(AdminApplicationDismissal $dismissal): void {}

            public function deleteByAdminId(string $adminId): void {}

            public function deleteByAppId(string $appId): void {}

            public function listAppIdsDismissedByAdmin(string $adminId): array
            {
                return [];
            }

            public function dismissedAppIdsFor(UserId $adminId): array
            {
                return $adminId->value() === $this->expectedActorId ? $this->dismissedIds : [];
            }

            public function clearForAppIdAnyAdmin(TenantId $tenantId, string $appType, string $appId): void {}
        };
    }

    /**
     * Helper exposed for anonymous-class stubs (PHP doesn't let them call parent privates).
     *
     * @return list<array{date: string, value: int}>
     */
    public static function constSeriesPublic(int $value): array
    {
        return self::constSeries($value);
    }
}
