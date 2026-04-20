<?php

declare(strict_types=1);

namespace Daems\Domain\Dismissal;

interface AdminApplicationDismissalRepositoryInterface
{
    /** Idempotent: upsert keyed by (admin_id, app_id). */
    public function save(AdminApplicationDismissal $dismissal): void;

    public function deleteByAdminId(string $adminId): void;

    public function deleteByAppId(string $appId): void;

    /** @return list<string> app_ids dismissed by this admin */
    public function listAppIdsDismissedByAdmin(string $adminId): array;
}
