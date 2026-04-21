<?php

declare(strict_types=1);

namespace Daems\Application\Project\GetProjectBySlugForLocale;

final class GetProjectBySlugForLocaleOutput
{
    /**
     * @param array<string, mixed>|null $project
     */
    public function __construct(public readonly ?array $project)
    {
    }
}
