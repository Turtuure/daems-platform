<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectCommentsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListProjectCommentsForAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly ?int $limit = null,
    ) {}
}
