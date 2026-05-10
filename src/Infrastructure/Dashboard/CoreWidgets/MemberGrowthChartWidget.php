<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;
use Daems\Infrastructure\Dashboard\WidgetRenderer;

final class MemberGrowthChartWidget extends Widget
{
    public function __construct(
        private readonly GetAdminStats $getAdminStats,
    ) {}

    public function id(): string             { return 'core.member_growth_chart'; }
    public function category(): WidgetCategory { return WidgetCategory::Charts; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(3); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.member_growth_chart.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.member_growth_chart.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        return WidgetRenderer::chart(
            I18n::t($this->labelKey()),
            'chart-' . str_replace('.', '-', $this->id()),
        );
    }

    public function data(TenantId $tenantId): array
    {
        $stats = $this->getAdminStats->execute($tenantId);
        return [
            'labels' => $stats->memberGrowth['labels'],
            'series' => $stats->memberGrowth['series'],
        ];
    }
}
