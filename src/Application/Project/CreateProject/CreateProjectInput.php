<?php

declare(strict_types=1);

namespace Daems\Application\Project\CreateProject;

final class CreateProjectInput
{
    public function __construct(
        public readonly string $title,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $summary,
        public readonly string $description,
        public readonly string $status,
    ) {}
}
