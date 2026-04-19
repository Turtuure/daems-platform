<?php

declare(strict_types=1);

namespace Daems\Application\Project\UpdateProject;

use Daems\Domain\Auth\ActingUser;

final class UpdateProjectInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $summary,
        public readonly string $description,
        public readonly string $status,
    ) {}
}
