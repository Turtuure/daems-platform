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

final class ApplicationsKpiWidget extends Widget
{
    private const ICON = '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>';

    public function __construct(
        private readonly GetAdminStats $getAdminStats,
    ) {}

    public function id(): string             { return 'core.applications_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.applications_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.applications_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:         (int) $d['value'],
            change:        (float) $d['change'],
            label:         I18n::t($this->labelKey()),
            color:         'amber',
            iconSvg:       self::ICON,
            sparklineId:   'spark-' . str_replace('.', '-', $this->id()),
            sparklineData: $d['sparkline'] ?? null,
        );
    }

    public function data(TenantId $tenantId): array
    {
        $stats = $this->getAdminStats->execute($tenantId);
        return [
            'value'     => $stats->pendingApplications,
            'change'    => $stats->applicationsChange,
            'sparkline' => $stats->applicationsSparkline,
        ];
    }
}
