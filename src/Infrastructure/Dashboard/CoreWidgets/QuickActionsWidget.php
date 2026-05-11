<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

final class QuickActionsWidget extends Widget
{
    public function __construct() {}

    public function id(): string             { return 'core.quick_actions'; }
    public function category(): WidgetCategory { return WidgetCategory::Actions; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.quick_actions.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.quick_actions.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        return '<div class="card"><div class="card__body">'
            . '<a class="btn btn--sm" href="/backstage/events/new">New event</a>'
            . '<a class="btn btn--sm" href="/backstage/members?view=pending">Review apps</a>'
            . '<a class="btn btn--sm" href="/backstage/insights/new">New insight</a>'
            . '</div></div>';
    }

    public function data(TenantId $tenantId): array
    {
        return ['actions' => ['new_event', 'review_apps', 'new_insight']];
    }
}
