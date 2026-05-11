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

final class TenantsKpiWidget extends Widget
{
    private const ICON = '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/>';

    public function __construct() {}

    public function id(): string             { return 'platform.tenants_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Gsa; }
    public function module(): string           { return 'platform'; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.tenants_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.tenants_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:   (int) $d['value'],
            change:  (float) $d['change'],
            label:   I18n::t($this->labelKey()),
            color:   'purple',
            iconSvg: self::ICON,
        );
    }

    public function data(TenantId $tenantId): array
    {
        // TODO(v1+): wire real data source (count of active tenants)
        return ['value' => 0, 'change' => 0.0];
    }
}
