<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Application\Platform\GetPlatformStats\GetPlatformStats;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;

final class ActivityFeedWidget extends Widget
{
    public function __construct(
        private readonly GetPlatformStats $stats,
    ) {}

    public function id(): string             { return 'core.activity_feed'; }
    public function category(): WidgetCategory { return WidgetCategory::Activity; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.activity_feed.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.activity_feed.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        $title = htmlspecialchars(I18n::t($this->labelKey()), ENT_QUOTES, 'UTF-8');

        $li = '';
        /** @var list<array{type:string, message:string, when:string}> $items */
        $items = $d['items'];
        foreach ($items as $it) {
            $ts = $it['when'] !== '' ? (string) date('d.m. H:i', (int) strtotime($it['when'])) : '';
            $li .= '<li>'
                . '<span class="dashboard-list__time">' . htmlspecialchars($ts, ENT_QUOTES, 'UTF-8') . '</span> · '
                . htmlspecialchars($it['message'], ENT_QUOTES, 'UTF-8')
                . '</li>';
        }
        if ($li === '') {
            $li = '<li class="empty">—</li>';
        }

        return '<div class="card"><div class="card__body">'
            . '<p class="card__title">' . $title . '</p>'
            . '<ul class="dashboard-list">' . $li . '</ul>'
            . '</div></div>';
    }

    public function data(TenantId $tenantId): array
    {
        $s = $this->stats->execute();
        return ['items' => $s->recentActivity];
    }
}
