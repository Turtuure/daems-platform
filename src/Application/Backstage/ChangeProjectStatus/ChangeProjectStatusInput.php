<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeProjectStatus;

use Daems\Domain\Auth\ActingUser;

final class ChangeProjectStatusInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $projectId,
        public readonly string $newStatus,
    ) {}
}
