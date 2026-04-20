<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\UnpinForumTopic;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: unpin a forum topic directly.
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load the topic (tenant-scoped) or NotFound
 *  - Set pinned=false, write audit row with prior + new pinned state
 */
final class UnpinForumTopic
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(UnpinForumTopicInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $topic = $this->forum->findTopicByIdForTenant($in->topicId, $tenantId);
        if ($topic === null) {
            throw new NotFoundException('topic_not_found');
        }

        $this->forum->setTopicPinnedForTenant($in->topicId, $tenantId, false);

        $now      = new DateTimeImmutable();
        $actingId = $in->acting->id->value();

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'topic',
            $in->topicId,
            ForumModerationAuditEntry::ACTION_UNPINNED,
            ['pinned' => $topic->pinned()],
            ['pinned' => false],
            null,
            $actingId,
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
