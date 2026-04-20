<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

interface IdGeneratorInterface
{
    /** Returns a new unique identifier string (UUID-formatted). */
    public function generate(): string;
}
