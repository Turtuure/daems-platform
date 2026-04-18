<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumCategory;

final class GetForumCategoryOutput
{
    public function __construct(
        public readonly ?array $data,
    ) {}
}
