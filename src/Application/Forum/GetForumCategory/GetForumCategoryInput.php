<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumCategory;

final class GetForumCategoryInput
{
    public function __construct(
        public readonly string $slug,
    ) {}
}
