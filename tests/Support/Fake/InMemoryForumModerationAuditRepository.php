<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryForumModerationAuditRepository implements ForumModerationAuditRepositoryInterface
{
    /** @var list<ForumModerationAuditEntry> */
    public array $rows = [];

    public function record(ForumModerationAuditEntry $entry): void
    {
        $this->rows[] = $entry;
    }

    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = []): array
    {
        $action    = (isset($filters['action']) && $filters['action'] !== '') ? $filters['action'] : null;
        $performer = (isset($filters['performer']) && $filters['performer'] !== '') ? $filters['performer'] : null;

        $filtered = [];
        foreach ($this->rows as $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($action !== null && $r->action() !== $action) {
                continue;
            }
            if ($performer !== null && $r->performedBy() !== $performer) {
                continue;
            }
            $filtered[] = $r;
        }

        usort(
            $filtered,
            static fn(ForumModerationAuditEntry $a, ForumModerationAuditEntry $b): int => $b->createdAt() <=> $a->createdAt(),
        );

        return array_values(array_slice($filtered, 0, $limit));
    }
}
