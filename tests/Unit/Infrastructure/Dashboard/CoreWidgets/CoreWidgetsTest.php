<?php
declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dashboard\CoreWidgets;

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Domain\Admin\AdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Dashboard\CoreWidgets\ActivityFeedWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\ApplicationsKpiWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\MemberGrowthChartWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\MembersKpiWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\PendingAppsListWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\PlatformActivityChartWidget;
use Daems\Infrastructure\Dashboard\CoreWidgets\QuickActionsWidget;
use PHPUnit\Framework\TestCase;

final class CoreWidgetsTest extends TestCase
{
    public function test_metadata_for_all_core_widgets(): void
    {
        $stats = $this->fakeStats();

        $cases = [
            ['core.members_kpi',              WidgetCategory::Numbers,  1, MinRole::Admin, new MembersKpiWidget($stats)],
            ['core.applications_kpi',         WidgetCategory::Numbers,  1, MinRole::Admin, new ApplicationsKpiWidget($stats)],
            ['core.member_growth_chart',      WidgetCategory::Charts,   3, MinRole::Admin, new MemberGrowthChartWidget($stats)],
            ['core.platform_activity_chart',  WidgetCategory::Charts,   3, MinRole::Admin, new PlatformActivityChartWidget()],
            ['core.quick_actions',            WidgetCategory::Actions,  1, MinRole::Admin, new QuickActionsWidget()],
            ['core.pending_apps_list',        WidgetCategory::Lists,    2, MinRole::Admin, new PendingAppsListWidget()],
            ['core.activity_feed',            WidgetCategory::Activity, 2, MinRole::Admin, new ActivityFeedWidget()],
        ];

        foreach ($cases as [$id, $cat, $span, $role, $w]) {
            self::assertSame($id, $w->id());
            self::assertSame($cat, $w->category());
            self::assertSame($span, $w->defaultSpan()->value());
            self::assertSame($role, $w->minRole());
            self::assertNull($w->module());
        }
    }

    public function test_members_kpi_data(): void
    {
        $w = new MembersKpiWidget($this->fakeStats());
        $d = $w->data(TenantId::generate());
        self::assertSame(412, $d['value']);
        self::assertSame(5.0, $d['change']);
    }

    public function test_applications_kpi_data(): void
    {
        $w = new ApplicationsKpiWidget($this->fakeStats());
        $d = $w->data(TenantId::generate());
        self::assertSame(7, $d['value']);
        self::assertSame(2.0, $d['change']);
    }

    public function test_member_growth_chart_data(): void
    {
        $w = new MemberGrowthChartWidget($this->fakeStats());
        $d = $w->data(TenantId::generate());
        self::assertSame(['M1', 'M2', 'M3'], $d['labels']);
        self::assertSame([10, 20, 30], $d['series']);
    }

    public function test_platform_activity_chart_data_stub(): void
    {
        $w = new PlatformActivityChartWidget();
        $d = $w->data(TenantId::generate());
        self::assertSame([], $d['labels']);
        self::assertSame([], $d['series']);
    }

    public function test_quick_actions_data(): void
    {
        $w = new QuickActionsWidget();
        $d = $w->data(TenantId::generate());
        self::assertContains('new_event', $d['actions']);
        self::assertContains('review_apps', $d['actions']);
        self::assertContains('new_insight', $d['actions']);
    }

    public function test_pending_apps_list_data_stub(): void
    {
        $w = new PendingAppsListWidget();
        $d = $w->data(TenantId::generate());
        self::assertSame([], $d['items']);
    }

    public function test_activity_feed_data_stub(): void
    {
        $w = new ActivityFeedWidget();
        $d = $w->data(TenantId::generate());
        self::assertSame([], $d['items']);
    }

    public function test_render_members_kpi_returns_html_with_value(): void
    {
        $w    = new MembersKpiWidget($this->fakeStats());
        $html = $w->render(TenantId::generate(), $this->fakeUser());
        self::assertStringContainsString('card', $html);
        self::assertStringContainsString('412', $html);
    }

    public function test_render_quick_actions_contains_links(): void
    {
        $w    = new QuickActionsWidget();
        $html = $w->render(TenantId::generate(), $this->fakeUser());
        self::assertStringContainsString('/backstage/events/new', $html);
        self::assertStringContainsString('/backstage/members?view=pending', $html);
        self::assertStringContainsString('/backstage/insights/new', $html);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function fakeStats(): GetAdminStats
    {
        $repo = new class implements AdminStatsRepositoryInterface {
            public function getStatsForTenant(TenantId $tenantId): AdminStats
            {
                return new AdminStats(
                    members: 412,
                    pendingApplications: 7,
                    upcomingEvents: 3,
                    activeProjects: 11,
                    membersSparkline: [1, 2, 3],
                    applicationsSparkline: [],
                    eventsSparkline: [],
                    projectsSparkline: [],
                    forumSparkline: [],
                    insightsSparkline: [],
                    membersChange: 5.0,
                    applicationsChange: 2.0,
                    eventsChange: 0.0,
                    projectsChange: 1.0,
                    memberGrowth: ['labels' => ['M1', 'M2', 'M3'], 'series' => [10, 20, 30]],
                );
            }

            /** @return array{ labels: string[], series: int[] } */
            public function getMemberGrowthForTenant(string $period, TenantId $tenantId): array
            {
                return ['labels' => [], 'series' => []];
            }
        };

        return new GetAdminStats($repo);
    }

    private function fakeUser(): \Daems\Domain\User\User
    {
        $class = new \ReflectionClass(\Daems\Domain\User\User::class);
        return $class->newInstanceWithoutConstructor();
    }
}
