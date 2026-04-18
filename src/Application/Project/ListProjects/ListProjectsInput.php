<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjects;

final class ListProjectsInput
{
    public function __construct(
        public readonly ?string $category = null,
        public readonly ?string $status = null,
        public readonly ?string $search = null,
    ) {}
}
