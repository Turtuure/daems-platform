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

final class TenantStatusGridWidget extends Widget
{
    public function __construct(
        private readonly GetPlatformStats $stats,
    ) {}

    public function id(): string             { return 'platform.tenant_status_grid'; }
    public function category(): WidgetCategory { return WidgetCategory::Lists; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(4); }
    public function minRole(): MinRole         { return MinRole::Gsa; }
    public function module(): string           { return 'platform'; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.tenant_status_grid.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.tenant_status_grid.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        $title = htmlspecialchars(I18n::t($this->labelKey()), ENT_QUOTES, 'UTF-8');

        $rows = '';
        /** @var list<array{slug:string, name:string, suspended:bool, members:int}> $tenants */
        $tenants = $d['tenants'];
        foreach ($tenants as $t) {
            $statusLabel = $t['suspended']
                ? '<span class="badge badge--error">' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.status.suspended'), ENT_QUOTES, 'UTF-8') . '</span>'
                : '<span class="badge badge--success">' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.status.active'), ENT_QUOTES, 'UTF-8') . '</span>';
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td><code>' . htmlspecialchars($t['slug'], ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td>' . $statusLabel . '</td>'
                . '<td style="text-align:right;">' . $t['members'] . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">—</td></tr>';
        }

        return '<div class="card"><div class="card__body">'
            . '<p class="card__title">' . $title . '</p>'
            . '<table class="data-table">'
            . '<thead><tr>'
            . '<th>' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.col.name'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.col.slug'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.col.status'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th style="text-align:right;">' . htmlspecialchars(I18n::t('backstage.dashboard.tenant.col.members'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div></div>';
    }

    public function data(TenantId $tenantId): array
    {
        $s = $this->stats->execute();
        return ['tenants' => $s->tenants];
    }
}
