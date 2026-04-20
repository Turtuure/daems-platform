<?php

declare(strict_types=1);

namespace Daems\Domain\Dismissal;

use Daems\Domain\Tenant\TenantId;

interface AdminApplicationDismissalRepositoryInterface
{
    /** Idempotent: upsert keyed by (admin_id, app_id). */
    public function save(AdminApplicationDismissal $dismissal): void;

    public function deleteByAdminId(string $adminId): void;

    public function deleteByAppId(string $appId): void;

    /** @return list<string> app_ids dismissed by this admin */
    public function listAppIdsDismissedByAdmin(string $adminId): array;

    /**
     * Clears all admin dismissals for the given (app_type, app_id) so every admin re-sees the toast.
     *
     * $tenantId is accepted for future-proofing even though the current schema scopes uniqueness globally
     * via admin_id + app_id. Implementations SHOULD ignore $tenantId until a tenant_id column is added.
     */
    public function clearForAppIdAnyAdmin(TenantId $tenantId, string $appType, string $appId): void;
}
