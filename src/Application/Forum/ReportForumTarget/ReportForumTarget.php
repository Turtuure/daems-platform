<?php

declare(strict_types=1);

namespace Daems\Application\Forum\ReportForumTarget;

use DateTimeImmutable;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

/**
 * User-facing use case: an authenticated member reports a post or topic.
 *
 * Behavior:
 *  - Validates target exists within the acting tenant (via ForumRepository lookups).
 *  - Validates reason_category against the canonical ForumReport::REASON_CATEGORIES whitelist.
 *  - Trims + caps reason_detail to 500 chars; empty string → null.
 *  - Upserts the ForumReport (InMemory / SQL both enforce dedup on reporter+target).
 *  - Clears any matching admin-inbox dismissals so the reminder toast re-surfaces for all admins.
 */
final class ReportForumTarget
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    ) {}

    public function execute(ReportForumTargetInput $in): ReportForumTargetOutput
    {
        $tenantId = $in->acting->activeTenant;

        if (!in_array($in->reasonCategory, ForumReport::REASON_CATEGORIES, true)) {
            throw new InvalidArgumentException('invalid_reason_category');
        }

        if ($in->targetType === ForumReport::TARGET_POST) {
            if ($this->forum->findPostByIdForTenant($in->targetId, $tenantId) === null) {
                throw new NotFoundException('post_not_found');
            }
        } elseif ($in->targetType === ForumReport::TARGET_TOPIC) {
            if ($this->forum->findTopicByIdForTenant($in->targetId, $tenantId) === null) {
                throw new NotFoundException('topic_not_found');
            }
        } else {
            throw new InvalidArgumentException('invalid_target_type');
        }

        $detail = $in->reasonDetail !== null ? trim($in->reasonDetail) : null;
        if ($detail === '') {
            $detail = null;
        }
        if ($detail !== null && strlen($detail) > 500) {
            $detail = substr($detail, 0, 500);
        }

        $now = new DateTimeImmutable();
        $report = new ForumReport(
            ForumReportId::generate(),
            $tenantId,
            $in->targetType,
            $in->targetId,
            $in->acting->id->value(),
            $in->reasonCategory,
            $detail,
            ForumReport::STATUS_OPEN,
            null,
            null,
            null,
            null,
            $now->format('Y-m-d H:i:s'),
        );
        $this->reports->upsert($report);

        $this->dismissals->clearForAppIdAnyAdmin(
            $tenantId,
            'forum_report',
            $in->targetType . ':' . $in->targetId,
        );

        return new ReportForumTargetOutput(true);
    }
}
