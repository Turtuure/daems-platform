<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class DeleteForumCategoryAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $id,
    ) {}
}
