<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByEdit;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

/**
 * Admin-side use case: resolve a forum report by OVERWRITING the post content.
 *
 * Contract:
 *  - Only `post` targets are supported (topics cannot be edited via this flow).
 *  - New content must be non-empty after trim.
 *  - Original content is preserved as `original_payload.content` in the audit row,
 *    new content + edited_at timestamp land in `new_payload`.
 *  - All OPEN reports for the target are flipped to resolved with action='edited'.
 */
final class ResolveForumReportByEdit
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ResolveForumReportByEditInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        if ($in->targetType !== ForumReport::TARGET_POST) {
            throw new InvalidArgumentException('cannot_edit_topic');
        }

        $newContent = trim($in->newContent);
        if ($newContent === '') {
            throw new InvalidArgumentException('content_required');
        }

        $post = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
        if ($post === null) {
            throw new NotFoundException('post_not_found');
        }

        $now    = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $originalContent = $post->content();

        $this->forum->updatePostContentForTenant($in->targetId, $tenantId, $newContent, $nowStr);

        $actingId = $in->acting->id->value();

        $this->reports->resolveAllForTarget(
            ForumReport::TARGET_POST,
            $in->targetId,
            $tenantId,
            'edited',
            $actingId,
            $in->note,
            $now,
        );

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            ForumReport::TARGET_POST,
            $in->targetId,
            ForumModerationAuditEntry::ACTION_EDITED,
            ['content' => $originalContent],
            ['content' => $newContent, 'edited_at' => $nowStr],
            $in->note,
            $actingId,
            null,
            $nowStr,
        ));
    }
}
