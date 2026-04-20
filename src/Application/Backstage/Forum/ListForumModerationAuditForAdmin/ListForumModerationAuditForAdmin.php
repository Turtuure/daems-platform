<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;

/**
 * Admin-side use case: list recent forum moderation audit entries for the acting tenant.
 *
 * Behavior:
 *  - Authorization: acting user must be an admin in the active tenant or a platform admin.
 *  - Returns entries newest-first (delegated to the repository).
 *  - Honours optional filters (`action`, `performer`) and an upper `limit`.
 */
final class ListForumModerationAuditForAdmin
{
    public function __construct(
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ListForumModerationAuditForAdminInput $in): ListForumModerationAuditForAdminOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        return new ListForumModerationAuditForAdminOutput(
            $this->audit->listRecentForTenant($tenantId, $in->limit, $in->filters),
        );
    }
}
