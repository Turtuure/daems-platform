<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\PlatformWidgets;

use Daems\Application\Platform\GetPlatformStats\GetPlatformStats;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;
use Daems\Infrastructure\Dashboard\WidgetRenderer;

final class DbSizeKpiWidget extends Widget
{
    private const ICON = '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>';

    public function __construct(
        private readonly GetPlatformStats $stats,
    ) {}

    public function id(): string             { return 'platform.db_size_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Gsa; }
    public function module(): string           { return 'platform'; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.db_size_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.db_size_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:   (int) $d['value'],
            change:  (float) $d['change'],
            label:   I18n::t($this->labelKey()) . ' (MB)',
            color:   'green',
            iconSvg: self::ICON,
        );
    }

    public function data(TenantId $tenantId): array
    {
        $s = $this->stats->execute();
        return [
            'value'  => $s->dbSizeMb,
            'change' => 0.0,
        ];
    }
}
