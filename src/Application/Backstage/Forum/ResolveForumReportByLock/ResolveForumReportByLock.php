<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByLock;

use DateTimeImmutable;
use InvalidArgumentException;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: resolve a forum report for a TOPIC by locking the topic.
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Reject when target is a post (cannot_lock_post)
 *  - Load the topic (tenant-scoped) or NotFound
 *  - Lock the topic, resolve its open reports with action='locked', write an audit row
 */
final class ResolveForumReportByLock
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ResolveForumReportByLockInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }
        if ($in->targetType !== 'topic') {
            throw new InvalidArgumentException('cannot_lock_post');
        }

        $topic = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
        if ($topic === null) {
            throw new NotFoundException('topic_not_found');
        }

        $this->forum->setTopicLockedForTenant($in->targetId, $tenantId, true);

        $now       = new DateTimeImmutable();
        $actingId  = $in->acting->id->value();

        $this->reports->resolveAllForTarget(
            'topic',
            $in->targetId,
            $tenantId,
            'locked',
            $actingId,
            $in->note,
            $now,
        );

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'topic',
            $in->targetId,
            'locked',
            ['locked' => $topic->locked()],
            ['locked' => true],
            $in->note,
            $actingId,
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
