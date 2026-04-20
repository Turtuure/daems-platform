<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: hard-delete a forum post directly (no report context).
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load the post (tenant-scoped), NotFound if missing
 *  - Capture a pre-delete payload snapshot
 *  - Delete
 *  - Write a ForumModerationAuditEntry with action='deleted', relatedReportId=null
 */
final class DeleteForumPostAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(DeleteForumPostAsAdminInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $post = $this->forum->findPostByIdForTenant($in->postId, $tenantId);
        if ($post === null) {
            throw new NotFoundException('post_not_found');
        }

        $payload = [
            'content'   => $post->content(),
            'topic_id'  => $post->topicId(),
            'author_id' => $post->userId(),
        ];

        $this->forum->deletePostForTenant($in->postId, $tenantId);

        $now = new DateTimeImmutable();
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'post',
            $in->postId,
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
