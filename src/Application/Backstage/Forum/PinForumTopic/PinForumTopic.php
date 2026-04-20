<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\PinForumTopic;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: pin a forum topic directly.
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load the topic (tenant-scoped) or NotFound
 *  - Set pinned=true, write audit row with prior + new pinned state
 */
final class PinForumTopic
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(PinForumTopicInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $topic = $this->forum->findTopicByIdForTenant($in->topicId, $tenantId);
        if ($topic === null) {
            throw new NotFoundException('topic_not_found');
        }

        $this->forum->setTopicPinnedForTenant($in->topicId, $tenantId, true);

        $now      = new DateTimeImmutable();
        $actingId = $in->acting->id->value();

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'topic',
            $in->topicId,
            ForumModerationAuditEntry::ACTION_PINNED,
            ['pinned' => $topic->pinned()],
            ['pinned' => true],
            null,
            $actingId,
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
