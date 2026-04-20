<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DismissForumReport;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReportRepositoryInterface;

/**
 * Admin-side use case: dismiss all open reports for a target as "perusteettomia".
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Mark all open reports for (targetType, targetId, tenantId) as dismissed
 *
 * No moderation audit row is written — the dismissed status on the reports
 * themselves is the trail. Idempotent: calling with zero open reports is a no-op.
 */
final class DismissForumReport
{
    public function __construct(
        private readonly ForumReportRepositoryInterface $reports,
    ) {}

    public function execute(DismissForumReportInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $now = new DateTimeImmutable();
        $this->reports->dismissAllForTarget(
            $in->targetType,
            $in->targetId,
            $tenantId,
            $in->acting->id->value(),
            $in->note,
            $now,
        );
        // No audit row — dismissed status on reports is the trail.
    }
}
