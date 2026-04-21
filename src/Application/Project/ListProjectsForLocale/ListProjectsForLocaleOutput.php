<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjectsForLocale;

final class ListProjectsForLocaleOutput
{
    /**
     * @param list<array<string, mixed>> $projects
     */
    public function __construct(public readonly array $projects)
    {
    }
}
