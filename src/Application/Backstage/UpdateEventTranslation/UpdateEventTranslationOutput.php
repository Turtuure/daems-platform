<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEventTranslation;

final class UpdateEventTranslationOutput
{
    /**
     * @param array<string, array{filled: int, total: int}> $coverage
     */
    public function __construct(public readonly array $coverage)
    {
    }
}
