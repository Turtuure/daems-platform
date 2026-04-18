<?php

declare(strict_types=1);

namespace Daems\Application\Project\LeaveProject;

final class LeaveProjectInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userId,
    ) {}
}
