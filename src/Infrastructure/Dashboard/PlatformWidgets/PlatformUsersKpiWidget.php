<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\PlatformWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Infrastructure\Dashboard\WidgetRenderer;

final class PlatformUsersKpiWidget extends Widget
{
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
        return WidgetRenderer::kpi($d['value'], (float) $d['change']);
    }

    public function data(TenantId $tenantId): array
    {
        // TODO(v1+): wire real data source (count of platform users across all tenants)
        return ['value' => 0, 'change' => 0];
    }
}
