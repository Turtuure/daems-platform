<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumTopicsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListForumTopicsForAdminInput
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly int $limit = 100,
        public readonly array $filters = [],
    ) {}
}
