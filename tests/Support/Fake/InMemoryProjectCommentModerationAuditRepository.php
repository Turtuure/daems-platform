<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryProjectCommentModerationAuditRepository implements ProjectCommentModerationAuditRepositoryInterface
{
    /** @var list<ProjectCommentModerationAudit> */
    public array $rows = [];

    public function save(ProjectCommentModerationAudit $a): void
    {
        $this->rows[] = $a;
    }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $filtered = array_values(array_filter(
            $this->rows,
            static fn(ProjectCommentModerationAudit $r): bool => $r->tenantId->equals($tenantId),
        ));
        usort($filtered, static fn(ProjectCommentModerationAudit $a, ProjectCommentModerationAudit $b): int => $b->createdAt <=> $a->createdAt);
        return array_slice($filtered, 0, $limit);
    }
}
