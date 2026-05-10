<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\PlatformWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;
use Daems\Infrastructure\Dashboard\WidgetRenderer;

final class TenantActivityChartWidget extends Widget
{
    public function __construct() {}

    public function id(): string             { return 'platform.tenant_activity_chart'; }
    public function category(): WidgetCategory { return WidgetCategory::Charts; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Gsa; }
    public function module(): string           { return 'platform'; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.tenant_activity_chart.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.tenant_activity_chart.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        return WidgetRenderer::chart(
            I18n::t($this->labelKey()),
            'chart-' . str_replace('.', '-', $this->id()),
        );
    }

    public function data(TenantId $tenantId): array
    {
        // TODO(v1+): wire real data source (cross-tenant activity time series)
        return ['labels' => [], 'series' => []];
    }
}
