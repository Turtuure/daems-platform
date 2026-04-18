<?php

declare(strict_types=1);

namespace Daems\Application\Project\GetProject;

final class GetProjectInput
{
    public function __construct(
        public readonly string $slug,
        public readonly ?string $userId = null,
    ) {}
}
