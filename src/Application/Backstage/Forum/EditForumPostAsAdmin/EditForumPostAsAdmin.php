<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\EditForumPostAsAdmin;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

/**
 * Admin-side use case: directly edit a forum post's content without any
 * associated report. The previous content is preserved in the audit trail.
 *
 * Contract:
 *  - Only admins in the active tenant (or platform admins) may invoke.
 *  - New content must be non-empty after trim.
 *  - Audit row: action='edited', original_payload.content = old content,
 *    new_payload = ['content' => new, 'edited_at' => timestamp].
 *  - No report resolution happens — this is pure direct edit.
 */
final class EditForumPostAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(EditForumPostAsAdminInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $newContent = trim($in->newContent);
        if ($newContent === '') {
            throw new InvalidArgumentException('content_required');
        }

        $post = $this->forum->findPostByIdForTenant($in->postId, $tenantId);
        if ($post === null) {
            throw new NotFoundException('post_not_found');
        }

        $now    = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $originalContent = $post->content();

        $this->forum->updatePostContentForTenant($in->postId, $tenantId, $newContent, $nowStr);

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            ForumReport::TARGET_POST,
            $in->postId,
            ForumModerationAuditEntry::ACTION_EDITED,
            ['content' => $originalContent],
            ['content' => $newContent, 'edited_at' => $nowStr],
            $in->note,
            $in->acting->id->value(),
            null,
            $nowStr,
        ));
    }
}
