<?php

declare(strict_types=1);

namespace Daems\Application\Project\ArchiveProject;

final class ArchiveProjectOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {}
}
