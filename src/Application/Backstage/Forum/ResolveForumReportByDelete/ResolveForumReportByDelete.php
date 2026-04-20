<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByDelete;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: resolve a forum report by HARD-DELETING the target (post or topic).
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load the target (post or topic), tenant-scoped, or NotFound
 *  - Snapshot the original payload (for audit reconstruction)
 *  - Delete the target (topic delete cascades posts via repo)
 *  - Resolve all open reports for the target with action='deleted'
 *  - Write a ForumModerationAuditEntry with action='deleted' and originalPayload
 */
final class ResolveForumReportByDelete
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ResolveForumReportByDeleteInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $originalPayload = [];
        if ($in->targetType === ForumReport::TARGET_POST) {
            $post = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
            if ($post === null) {
                throw new NotFoundException('post_not_found');
            }
            $originalPayload = [
                'author_id'  => $post->userId(),
                'content'    => $post->content(),
                'topic_id'   => $post->topicId(),
                'created_at' => $post->createdAt(),
            ];
            $this->forum->deletePostForTenant($in->targetId, $tenantId);
        } elseif ($in->targetType === ForumReport::TARGET_TOPIC) {
            $topic = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
            if ($topic === null) {
                throw new NotFoundException('topic_not_found');
            }
            $originalPayload = [
                'author_id'   => $topic->userId(),
                'title'       => $topic->title(),
                'category_id' => $topic->categoryId(),
                'created_at'  => $topic->createdAt(),
            ];
            $this->forum->deleteTopicForTenant($in->targetId, $tenantId);
        } else {
            throw new NotFoundException('invalid_target_type');
        }

        $now      = new DateTimeImmutable();
        $actingId = $in->acting->id->value();

        $this->reports->resolveAllForTarget(
            $in->targetType,
            $in->targetId,
            $tenantId,
            'deleted',
            $actingId,
            $in->note,
            $now,
        );

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            $in->targetType,
            $in->targetId,
            'deleted',
            $originalPayload,
            null,
            $in->note,
            $actingId,
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
