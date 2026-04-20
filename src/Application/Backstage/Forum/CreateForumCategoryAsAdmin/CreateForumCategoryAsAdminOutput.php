<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin;

final class CreateForumCategoryAsAdminOutput
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
    ) {}
}
