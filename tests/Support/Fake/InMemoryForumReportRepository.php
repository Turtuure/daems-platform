<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use DateTimeImmutable;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryForumReportRepository implements ForumReportRepositoryInterface
{
    /** @var array<string, ForumReport> keyed by report id */
    public array $reports = [];

    /** @var array<string, string> ($reporter:$targetType:$targetId) => reportId */
    private array $dedupIndex = [];

    public function upsert(ForumReport $report): void
    {
        $dedupKey = $report->reporterUserId() . ':' . $report->targetType() . ':' . $report->targetId();
        $existingId = $this->dedupIndex[$dedupKey] ?? null;

        if ($existingId !== null && $existingId !== $report->id()->value()) {
            // Replace the existing entry keyed by the old id with the new report (dedup on reporter+target).
            unset($this->reports[$existingId]);
        }

        $this->reports[$report->id()->value()] = $report;
        $this->dedupIndex[$dedupKey] = $report->id()->value();
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport
    {
        $r = $this->reports[$id] ?? null;
        if ($r === null) {
            return null;
        }
        return $r->tenantId()->equals($tenantId) ? $r : null;
    }

    public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array
    {
        $status = $filters['status'] ?? ForumReport::STATUS_OPEN;
        $filterTargetType = null;
        if (!empty($filters['target_type']) && is_string($filters['target_type'])) {
            $filterTargetType = $filters['target_type'];
        }

        /** @var array<string, list<ForumReport>> $groups keyed by "targetType:targetId" */
        $groups = [];
        foreach ($this->reports as $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($r->status() !== $status) {
                continue;
            }
            if ($filterTargetType !== null && $r->targetType() !== $filterTargetType) {
                continue;
            }
            $key = $r->targetType() . ':' . $r->targetId();
            $groups[$key][] = $r;
        }

        $out = [];
        foreach ($groups as $groupReports) {
            $reasonCounts = [];
            $ids = [];
            $earliest = null;
            $latest = null;
            foreach ($groupReports as $gr) {
                $cat = $gr->reasonCategory();
                $reasonCounts[$cat] = ($reasonCounts[$cat] ?? 0) + 1;
                $ids[] = $gr->id()->value();
                $created = $gr->createdAt();
                if ($earliest === null || $created < $earliest) {
                    $earliest = $created;
                }
                if ($latest === null || $created > $latest) {
                    $latest = $created;
                }
            }
            /** @var ForumReport $first */
            $first = $groupReports[0];
            $out[] = new AggregatedForumReport(
                $first->targetType(),
                $first->targetId(),
                count($groupReports),
                $reasonCounts,
                $ids,
                (string) $earliest,
                (string) $latest,
                $first->status(),
            );
        }

        // Sort by latestCreatedAt DESC to match SQL ORDER BY latest DESC.
        usort($out, static fn(AggregatedForumReport $a, AggregatedForumReport $b): int => strcmp($b->latestCreatedAt, $a->latestCreatedAt));

        return array_values($out);
    }

    public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array
    {
        $matches = [];
        foreach ($this->reports as $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($r->targetType() !== $targetType || $r->targetId() !== $targetId) {
                continue;
            }
            $matches[] = $r;
        }
        usort($matches, static fn(ForumReport $a, ForumReport $b): int => strcmp($b->createdAt(), $a->createdAt()));
        return array_values($matches);
    }

    public function resolveAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolutionAction,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $resolvedAt = $now->format('Y-m-d H:i:s');
        foreach ($this->reports as $id => $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($r->targetType() !== $targetType || $r->targetId() !== $targetId) {
                continue;
            }
            if ($r->status() !== ForumReport::STATUS_OPEN) {
                continue;
            }
            $this->reports[$id] = new ForumReport(
                ForumReportId::fromString($r->id()->value()),
                $r->tenantId(),
                $r->targetType(),
                $r->targetId(),
                $r->reporterUserId(),
                $r->reasonCategory(),
                $r->reasonDetail(),
                ForumReport::STATUS_RESOLVED,
                $resolvedAt,
                $resolvedBy,
                $note,
                $resolutionAction,
                $r->createdAt(),
            );
        }
    }

    public function dismissAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $resolvedAt = $now->format('Y-m-d H:i:s');
        foreach ($this->reports as $id => $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($r->targetType() !== $targetType || $r->targetId() !== $targetId) {
                continue;
            }
            if ($r->status() !== ForumReport::STATUS_OPEN) {
                continue;
            }
            $this->reports[$id] = new ForumReport(
                ForumReportId::fromString($r->id()->value()),
                $r->tenantId(),
                $r->targetType(),
                $r->targetId(),
                $r->reporterUserId(),
                $r->reasonCategory(),
                $r->reasonDetail(),
                ForumReport::STATUS_DISMISSED,
                $resolvedAt,
                $resolvedBy,
                $note,
                'dismissed',
                $r->createdAt(),
            );
        }
    }

    public function countOpenForTenant(TenantId $tenantId): int
    {
        $seen = [];
        foreach ($this->reports as $r) {
            if (!$r->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($r->status() !== ForumReport::STATUS_OPEN) {
                continue;
            }
            $seen[$r->targetType() . ':' . $r->targetId()] = true;
        }
        return count($seen);
    }
}
