<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListProjectsForAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly ?string $status,
        public readonly ?string $category,
        public readonly ?bool $featuredOnly,
        public readonly ?string $q,
    ) {}
}
