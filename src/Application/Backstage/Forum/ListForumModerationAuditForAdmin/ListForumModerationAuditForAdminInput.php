<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListForumModerationAuditForAdminInput
{
    /**
     * @param array{action?:string, performer?:string} $filters
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly int $limit = 200,
        public readonly array $filters = [],
        public readonly int $offset = 0,
    ) {}
}
