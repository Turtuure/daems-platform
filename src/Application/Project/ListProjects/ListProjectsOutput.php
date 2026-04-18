<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjects;

final class ListProjectsOutput
{
    public function __construct(
        public readonly array $projects,
    ) {}
}
