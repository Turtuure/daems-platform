<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\SetProjectFeatured;

use Daems\Domain\Auth\ActingUser;

final class SetProjectFeaturedInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $projectId,
        public readonly bool $featured,
    ) {}
}
