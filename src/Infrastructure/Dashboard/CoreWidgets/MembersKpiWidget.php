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

final class MembersKpiWidget extends Widget
{
    public function __construct(
        private readonly GetAdminStats $getAdminStats,
    ) {}

    public function id(): string             { return 'core.members_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.members_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.members_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi($d['value'], $d['change']);
    }

    public function data(TenantId $tenantId): array
    {
        $stats = $this->getAdminStats->execute($tenantId);
        return ['value' => $stats->members, 'change' => $stats->membersChange];
    }
}
