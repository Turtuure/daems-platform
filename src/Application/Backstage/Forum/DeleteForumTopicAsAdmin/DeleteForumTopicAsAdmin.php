<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: hard-delete a forum topic directly (no report context).
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load the topic (tenant-scoped), NotFound if missing
 *  - Capture a pre-delete payload snapshot
 *  - Delete (cascade handled by repo)
 *  - Write a ForumModerationAuditEntry with action='deleted', relatedReportId=null
 */
final class DeleteForumTopicAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(DeleteForumTopicAsAdminInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $topic = $this->forum->findTopicByIdForTenant($in->topicId, $tenantId);
        if ($topic === null) {
            throw new NotFoundException('topic_not_found');
        }

        $payload = [
            'title'       => $topic->title(),
            'category_id' => $topic->categoryId(),
            'author_id'   => $topic->userId(),
        ];

        $this->forum->deleteTopicForTenant($in->topicId, $tenantId);

        $now = new DateTimeImmutable();
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'topic',
            $in->topicId,
            ForumModerationAuditEntry::ACTION_DELETED,
            $payload,
            null,
            null,
            $in->acting->id->value(),
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
