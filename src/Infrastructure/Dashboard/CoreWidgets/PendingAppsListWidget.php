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
use Daems\Infrastructure\Dashboard\WidgetRenderer;

final class PendingAppsListWidget extends Widget
{
    public function __construct() {}

    public function id(): string             { return 'core.pending_apps_list'; }
    public function category(): WidgetCategory { return WidgetCategory::Lists; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.pending_apps_list.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.pending_apps_list.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        return WidgetRenderer::list(
            I18n::t($this->labelKey()),
            array_values(array_map('strval', $d['items'])),
        );
    }

    public function data(TenantId $tenantId): array
    {
        // TODO(v1+): replace with real ListPendingApplications use case in follow-up
        return ['items' => []];
    }
}
