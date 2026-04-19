<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectUpdate;

use Daems\Domain\Auth\ActingUser;

final class AddProjectUpdateInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $content,
    ) {}
}
