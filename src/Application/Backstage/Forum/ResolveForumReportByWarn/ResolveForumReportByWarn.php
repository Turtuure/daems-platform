<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByWarn;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumUserWarning;
use Daems\Domain\Forum\ForumUserWarningId;
use Daems\Domain\Forum\ForumUserWarningRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

/**
 * Admin-side use case: resolve all OPEN reports for a target by issuing a
 * formal warning to the target's author (post.userId() or topic.userId()).
 *
 * Writes three rows:
 *  1. forum_user_warnings — the warning itself (reason = admin's note)
 *  2. forum_reports.* — all OPEN reports for the target flipped to resolved action=warned
 *  3. forum_moderation_audit — action=warned with {author_id} as original_payload
 *
 * If the target has no author (legacy/anonymous content), throws
 * InvalidArgumentException('no_author_to_warn') — callers must surface a 422.
 */
final class ResolveForumReportByWarn
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumUserWarningRepositoryInterface $warnings,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ResolveForumReportByWarnInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $authorId = null;
        if ($in->targetType === ForumReport::TARGET_POST) {
            $p = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
            if ($p === null) {
                throw new NotFoundException('post_not_found');
            }
            $authorId = $p->userId();
        } elseif ($in->targetType === ForumReport::TARGET_TOPIC) {
            $t = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
            if ($t === null) {
                throw new NotFoundException('topic_not_found');
            }
            $authorId = $t->userId();
        } else {
            throw new NotFoundException('invalid_target_type');
        }

        if ($authorId === null) {
            throw new InvalidArgumentException('no_author_to_warn');
        }

        $now       = new DateTimeImmutable();
        $actingId  = $in->acting->id->value();
        $createdAt = $now->format('Y-m-d H:i:s');

        $this->warnings->record(new ForumUserWarning(
            ForumUserWarningId::generate(),
            $tenantId,
            $authorId,
            (string) $in->note,
            null,
            $actingId,
            $createdAt,
        ));

        $this->reports->resolveAllForTarget(
            $in->targetType,
            $in->targetId,
            $tenantId,
            'warned',
            $actingId,
            $in->note,
            $now,
        );

        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            $in->targetType,
            $in->targetId,
            ForumModerationAuditEntry::ACTION_WARNED,
            ['author_id' => $authorId],
            null,
            $in->note,
            $actingId,
            null,
            $createdAt,
        ));
    }
}
