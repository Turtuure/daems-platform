<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetProjectWithAllTranslations;

final class GetProjectWithAllTranslationsOutput
{
    /**
     * @param array<string, mixed> $project
     */
    public function __construct(public readonly array $project)
    {
    }
}
