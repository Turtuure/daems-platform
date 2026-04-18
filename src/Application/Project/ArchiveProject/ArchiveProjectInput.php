<?php

declare(strict_types=1);

namespace Daems\Application\Project\ArchiveProject;

final class ArchiveProjectInput
{
    public function __construct(public readonly string $slug) {}
}
