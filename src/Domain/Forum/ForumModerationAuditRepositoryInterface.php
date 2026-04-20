<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumModerationAuditRepositoryInterface
{
    public function record(ForumModerationAuditEntry $entry): void;

    /**
     * @param array{action?:string, performer?:string} $filters
     * @return list<ForumModerationAuditEntry>
     */
    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = []): array;
}
