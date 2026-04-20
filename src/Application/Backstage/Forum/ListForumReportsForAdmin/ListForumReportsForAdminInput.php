<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListForumReportsForAdminInput
{
    /**
     * @param array{status?:string, target_type?:string} $filters
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly array $filters,
        public readonly int $limit,
    ) {}
}
