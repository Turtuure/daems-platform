<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;

final class MembersByTierKpiWidget extends Widget
{
    public function __construct(
        private readonly AdminStatsRepositoryInterface $repo,
    ) {}

    public function id(): string             { return 'members.members_by_tier_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.members_by_tier_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.members_by_tier_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        $title = htmlspecialchars(I18n::t($this->labelKey()), ENT_QUOTES, 'UTF-8');

        $labels = [
            'supporting' => I18n::t('membership.type.supporting'),
            'basic'      => I18n::t('membership.type.basic'),
            'full'       => I18n::t('membership.type.full'),
            'honorary'   => I18n::t('membership.type.honorary'),
        ];

        $rows = '';
        foreach ($labels as $key => $label) {
            $count = (int) ($d[$key] ?? 0);
            $rows .= '<div class="tier-row">'
                . '<span class="tier-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span class="tier-count">' . $count . '</span>'
                . '</div>';
        }

        return '<div class="card"><div class="card__body">'
            . '<p class="card__title">' . $title . '</p>'
            . '<div class="tier-grid">' . $rows . '</div>'
            . '</div></div>';
    }

    public function data(TenantId $tenantId): array
    {
        return $this->repo->getMembersByTier($tenantId);
    }
}
