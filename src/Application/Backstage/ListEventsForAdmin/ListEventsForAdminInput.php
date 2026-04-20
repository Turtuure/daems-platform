<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListEventsForAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly ?string $status,
        public readonly ?string $type,
    ) {}
}
