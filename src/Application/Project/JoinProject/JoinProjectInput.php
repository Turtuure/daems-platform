<?php

declare(strict_types=1);

namespace Daems\Application\Project\JoinProject;

final class JoinProjectInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userId,
    ) {}
}
