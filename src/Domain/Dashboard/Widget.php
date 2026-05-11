<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

abstract class Widget
{
    abstract public function id(): string;
    abstract public function category(): WidgetCategory;
    abstract public function defaultSpan(): WidgetSpan;
    abstract public function minRole(): MinRole;

    /** null = core widget; otherwise module slug ('events', 'forum', etc.). 'platform' for GSA-only widgets. */
    public function module(): ?string { return null; }

    abstract public function labelKey(): string;
    abstract public function descriptionKey(): string;

    /** Server-rendered HTML for the widget body (no chrome — caller wraps in grid cell). */
    abstract public function render(TenantId $tenantId, User $user): string;

    /** JSON-serialisable data shape for the widget — used by GET /widget/{id}/data and may be inlined into render(). */
    abstract public function data(TenantId $tenantId): array;
}
