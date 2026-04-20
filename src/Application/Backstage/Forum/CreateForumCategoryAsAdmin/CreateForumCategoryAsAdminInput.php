<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class CreateForumCategoryAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $icon = '',
        public readonly string $description = '',
        public readonly int $sortOrder = 0,
    ) {}
}
