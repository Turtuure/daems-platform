<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Infrastructure\Dashboard\WidgetRenderer;
use Daems\Frontend\I18n;

final class OpenExpulsionsKpiWidget extends Widget
{
    private const ICON = '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>';

    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function id(): string              { return 'governance.open_expulsions_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.open_expulsions_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.open_expulsions_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::kpi(
            value:   (int) $d['count'],
            label:   I18n::t($this->labelKey()),
            color:   'red',
            iconSvg: self::ICON,
        );
    }

    /** @return array{count:int, hearing:int, awaiting_vote:int} */
    public function data(TenantId $tenantId): array
    {
        $hearing  = count($this->expulsions->listForTenant($tenantId, MemberExpulsionStatus::Hearing));
        $awaiting = count($this->expulsions->listForTenant($tenantId, MemberExpulsionStatus::AwaitingVote));
        return [
            'count'        => $hearing + $awaiting,
            'hearing'      => $hearing,
            'awaiting_vote' => $awaiting,
        ];
    }
}
