<?php

declare(strict_types=1);

namespace Daems\Application\Project\ArchiveProject;

use Daems\Domain\Auth\ActingUser;

final class ArchiveProjectInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $slug,
    ) {}
}
