<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin;

use Daems\Domain\Forum\ForumModerationAuditEntry;

final class ListForumModerationAuditForAdminOutput
{
    /**
     * @param list<ForumModerationAuditEntry> $entries
     */
    public function __construct(public readonly array $entries) {}
}
