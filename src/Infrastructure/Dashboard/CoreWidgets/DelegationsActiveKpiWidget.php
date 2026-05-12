<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Infrastructure\Dashboard\WidgetRenderer;
use Daems\Frontend\I18n;

final class DelegationsActiveKpiWidget extends Widget
{
    private const ICON = '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>';

    public function __construct(
        private readonly BoardDelegationRepositoryInterface $delegations,
    ) {}

    public function id(): string              { return 'governance.delegations_active_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.delegations_active_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.delegations_active_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:   (int) $d['count'],
            label:   I18n::t($this->labelKey()),
            color:   'purple',
            iconSvg: self::ICON,
        );
    }

    /** @return array{count:int} */
    public function data(TenantId $tenantId): array
    {
        $count = count($this->delegations->listActive($tenantId, new \DateTimeImmutable()));
        return ['count' => $count];
    }
}
