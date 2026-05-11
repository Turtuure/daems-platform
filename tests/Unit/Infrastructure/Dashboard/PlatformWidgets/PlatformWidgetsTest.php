<?php
declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dashboard\PlatformWidgets;

use Daems\Application\Platform\GetPlatformStats\GetPlatformStats;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Platform\PlatformStats;
use Daems\Domain\Platform\PlatformStatsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Dashboard\PlatformWidgets\DbSizeKpiWidget;
use Daems\Infrastructure\Dashboard\PlatformWidgets\PlatformUsersKpiWidget;
use Daems\Infrastructure\Dashboard\PlatformWidgets\TenantActivityChartWidget;
use Daems\Infrastructure\Dashboard\PlatformWidgets\TenantStatusGridWidget;
use Daems\Infrastructure\Dashboard\PlatformWidgets\TenantsKpiWidget;
use Daems\Infrastructure\Dashboard\PlatformWidgets\UptimeKpiWidget;
use PHPUnit\Framework\TestCase;

final class PlatformWidgetsTest extends TestCase
{
    private function stats(): GetPlatformStats
    {
        $repo = new class implements PlatformStatsRepositoryInterface {
            public function get(): PlatformStats
            {
                return new PlatformStats(
                    tenantCount:        0,
                    userCount:          0,
                    dbSizeMb:           0,
                    mysqlUptimeSeconds: 0,
                    usersSparkline:     [],
                    tenantsSparkline:   [],
                    activitySparkline:  [],
                    tenants:            [],
                    tenantActivity:     ['labels' => [], 'series' => []],
                    recentActivity:     [],
                );
            }
        };
        return new GetPlatformStats($repo);
    }

    /** @return list<\Daems\Domain\Dashboard\Widget> */
    private function widgets(): array
    {
        $s = $this->stats();
        return [
            new TenantsKpiWidget($s),
            new PlatformUsersKpiWidget($s),
            new DbSizeKpiWidget($s),
            new UptimeKpiWidget($s),
            new TenantStatusGridWidget($s),
            new TenantActivityChartWidget($s),
        ];
    }

    public function test_all_platform_widgets_have_correct_metadata(): void
    {
        foreach ($this->widgets() as $w) {
            self::assertSame(MinRole::Gsa, $w->minRole(), $w->id() . ' must require Gsa');
            self::assertSame('platform', $w->module(), $w->id() . ' must be in platform module');
            self::assertStringStartsWith('platform.', $w->id());
        }
    }

    public function test_platform_widget_ids_are_unique(): void
    {
        $ids = array_map(fn ($w) => $w->id(), $this->widgets());
        self::assertSame(count($ids), count(array_unique($ids)), 'All platform widget IDs must be unique');
    }

    public function test_all_platform_widgets_have_i18n_keys(): void
    {
        foreach ($this->widgets() as $w) {
            self::assertStringStartsWith('backstage.dashboard.widget.', $w->labelKey(), $w->id());
            self::assertStringStartsWith('backstage.dashboard.widget.', $w->descriptionKey(), $w->id());
        }
    }

    public function test_data_returns_expected_shape(): void
    {
        $tenantId = TenantId::generate();
        $s = $this->stats();

        $kpiWidgets = [
            new TenantsKpiWidget($s),
            new PlatformUsersKpiWidget($s),
            new DbSizeKpiWidget($s),
            new UptimeKpiWidget($s),
        ];

        foreach ($kpiWidgets as $w) {
            $d = $w->data($tenantId);
            self::assertArrayHasKey('value', $d, $w->id());
            self::assertArrayHasKey('change', $d, $w->id());
        }

        $grid = new TenantStatusGridWidget($s);
        $d    = $grid->data($tenantId);
        self::assertArrayHasKey('tenants', $d);

        $chart = new TenantActivityChartWidget($s);
        $d     = $chart->data($tenantId);
        self::assertArrayHasKey('labels', $d);
        self::assertArrayHasKey('series', $d);
    }
}
