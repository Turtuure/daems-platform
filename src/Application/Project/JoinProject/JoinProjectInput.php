<?php

declare(strict_types=1);

namespace Daems\Application\Project\JoinProject;

use Daems\Domain\Auth\ActingUser;

final class JoinProjectInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $slug,
    ) {}
}
