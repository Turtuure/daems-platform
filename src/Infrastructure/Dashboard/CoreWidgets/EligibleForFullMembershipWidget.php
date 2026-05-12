<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;

final class EligibleForFullMembershipWidget extends Widget
{
    /**
     * @param callable(TenantId, \DateTimeImmutable):list<array{id:string, name:string, member_number:?string, membership_started_at:string, months_since_join:int}> $lookup
     */
    public function __construct(private $lookup) {}

    public function id(): string              { return 'governance.eligible_for_full_membership'; }
    public function category(): WidgetCategory { return WidgetCategory::Lists; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.eligible_for_full_membership.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.eligible_for_full_membership.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d     = $this->data($tenantId);
        $title = htmlspecialchars(I18n::t($this->labelKey()), ENT_QUOTES, 'UTF-8');
        $rows  = $d['users'];
        $count = $d['count'];

        if ($count === 0) {
            return '<div class="card"><div class="card__body">'
                . '<p class="card__title">' . $title . '</p>'
                . '<p class="color-secondary">—</p>'
                . '</div></div>';
        }

        $li = '';
        foreach ($rows as $row) {
            $name    = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
            $months  = (int) $row['months_since_join'];
            $li .= '<li><span class="list-item-label">' . $name . '</span>'
                . '<span class="list-item-meta color-secondary">' . $months . ' mo</span>'
                . '</li>';
        }

        return '<div class="card"><div class="card__body">'
            . '<p class="card__title">' . $title . ' <span class="badge badge--neutral">' . $count . '</span></p>'
            . '<ul class="dashboard-list">' . $li . '</ul>'
            . '</div></div>';
    }

    /**
     * @return array{users:list<array{id:string, name:string, member_number:?string, membership_started_at:string, months_since_join:int}>, count:int}
     */
    public function data(TenantId $tenantId): array
    {
        $lookup = $this->lookup;
        $rows   = $lookup($tenantId, new \DateTimeImmutable());
        return ['users' => $rows, 'count' => count($rows)];
    }
}
