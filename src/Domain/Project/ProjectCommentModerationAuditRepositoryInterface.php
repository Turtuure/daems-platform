<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectCommentModerationAuditRepositoryInterface
{
    public function save(ProjectCommentModerationAudit $audit): void;

    /** @return list<ProjectCommentModerationAudit> */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;
}
