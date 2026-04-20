<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class UpdateForumCategoryAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $id,
        public readonly ?string $slug = null,
        public readonly ?string $name = null,
        public readonly ?string $icon = null,
        public readonly ?string $description = null,
        public readonly ?int $sortOrder = null,
    ) {}
}
