<?php
declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dashboard\PlatformWidgets;

use Daems\Domain\Dashboard\MinRole;
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
    public function test_all_platform_widgets_have_correct_metadata(): void
    {
        $widgets = [
            new TenantsKpiWidget(),
            new PlatformUsersKpiWidget(),
            new DbSizeKpiWidget(),
            new UptimeKpiWidget(),
            new TenantStatusGridWidget(),
            new TenantActivityChartWidget(),
        ];

        foreach ($widgets as $w) {
            self::assertSame(MinRole::Gsa, $w->minRole(), $w->id() . ' must require Gsa');
            self::assertSame('platform', $w->module(), $w->id() . ' must be in platform module');
            self::assertStringStartsWith('platform.', $w->id());
        }
    }

    public function test_platform_widget_ids_are_unique(): void
    {
        $widgets = [
            new TenantsKpiWidget(),
            new PlatformUsersKpiWidget(),
            new DbSizeKpiWidget(),
            new UptimeKpiWidget(),
            new TenantStatusGridWidget(),
            new TenantActivityChartWidget(),
        ];

        $ids = array_map(fn ($w) => $w->id(), $widgets);
        self::assertSame(count($ids), count(array_unique($ids)), 'All platform widget IDs must be unique');
    }

    public function test_all_platform_widgets_have_i18n_keys(): void
    {
        $widgets = [
            new TenantsKpiWidget(),
            new PlatformUsersKpiWidget(),
            new DbSizeKpiWidget(),
            new UptimeKpiWidget(),
            new TenantStatusGridWidget(),
            new TenantActivityChartWidget(),
        ];

        foreach ($widgets as $w) {
            self::assertStringStartsWith('backstage.dashboard.widget.', $w->labelKey(), $w->id());
            self::assertStringStartsWith('backstage.dashboard.widget.', $w->descriptionKey(), $w->id());
        }
    }

    public function test_stub_data_returns_expected_shape(): void
    {
        $tenantId = TenantId::generate();

        $kpiWidgets = [
            new TenantsKpiWidget(),
            new PlatformUsersKpiWidget(),
            new DbSizeKpiWidget(),
            new UptimeKpiWidget(),
        ];

        foreach ($kpiWidgets as $w) {
            $d = $w->data($tenantId);
            self::assertArrayHasKey('value', $d, $w->id());
            self::assertArrayHasKey('change', $d, $w->id());
        }

        $grid = new TenantStatusGridWidget();
        $d    = $grid->data($tenantId);
        self::assertArrayHasKey('tenants', $d);

        $chart = new TenantActivityChartWidget();
        $d     = $chart->data($tenantId);
        self::assertArrayHasKey('labels', $d);
        self::assertArrayHasKey('series', $d);
    }
}
