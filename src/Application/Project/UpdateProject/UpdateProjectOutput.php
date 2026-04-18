<?php

declare(strict_types=1);

namespace Daems\Application\Project\UpdateProject;

final class UpdateProjectOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {}
}
