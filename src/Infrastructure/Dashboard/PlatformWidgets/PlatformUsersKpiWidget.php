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

final class PlatformUsersKpiWidget extends Widget
{
    private const ICON = '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>';

    public function __construct() {}

    public function id(): string             { return 'platform.users_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Gsa; }
    public function module(): string           { return 'platform'; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.users_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.users_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:   (int) $d['value'],
            change:  (float) $d['change'],
            label:   I18n::t($this->labelKey()),
            color:   'blue',
            iconSvg: self::ICON,
        );
    }

    public function data(TenantId $tenantId): array
    {
        // TODO(v1+): wire real data source (count of platform users across all tenants)
        return ['value' => 0, 'change' => 0.0];
    }
}
