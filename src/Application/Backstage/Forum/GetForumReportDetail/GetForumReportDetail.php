<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\GetForumReportDetail;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: fetch the full detail view for a single reported target.
 *
 * Returns three bundles so the controller/template can render one cohesive card:
 *  - `aggregated` — computed from the raw list (reasonCounts, earliest/latest, etc.)
 *  - `rawReports` — every ForumReport row that targets the given target (all statuses)
 *  - `targetContent` — a minimal snapshot of the underlying post/topic for preview
 */
final class GetForumReportDetail
{
    public function __construct(
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(GetForumReportDetailInput $in): GetForumReportDetailOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $raw = $this->reports->listRawForTargetForTenant($in->targetType, $in->targetId, $tenantId);
        if ($raw === []) {
            throw new NotFoundException('no_reports_for_target');
        }

        if ($in->targetType === ForumReport::TARGET_POST) {
            $post = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
            if ($post === null) {
                throw new NotFoundException('post_not_found');
            }
            $targetContent = [
                'author'     => $post->authorName(),
                'content'    => $post->content(),
                'created_at' => $post->createdAt(),
            ];
        } elseif ($in->targetType === ForumReport::TARGET_TOPIC) {
            $topic = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
            if ($topic === null) {
                throw new NotFoundException('topic_not_found');
            }
            $targetContent = [
                'author'     => $topic->authorName(),
                'title'      => $topic->title(),
                'created_at' => $topic->createdAt(),
            ];
        } else {
            throw new NotFoundException('invalid_target_type');
        }

        $aggregated = $this->buildAggregated($in->targetType, $in->targetId, $raw);

        return new GetForumReportDetailOutput($aggregated, $raw, $targetContent);
    }

    /**
     * @param list<ForumReport> $raw must be non-empty (caller enforces)
     */
    private function buildAggregated(string $targetType, string $targetId, array $raw): AggregatedForumReport
    {
        $reasonCounts = [];
        $ids = [];
        $createdAts = [];
        foreach ($raw as $r) {
            $reasonCounts[$r->reasonCategory()] = ($reasonCounts[$r->reasonCategory()] ?? 0) + 1;
            $ids[] = $r->id()->value();
            $createdAts[] = $r->createdAt();
        }
        sort($createdAts);
        $earliest = $createdAts[0];
        $latest   = $createdAts[count($createdAts) - 1];

        return new AggregatedForumReport(
            $targetType,
            $targetId,
            count($raw),
            $reasonCounts,
            $ids,
            $earliest,
            $latest,
            $raw[0]->status(),
        );
    }
}
