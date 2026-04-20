<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventRegistrations;

use Daems\Domain\Auth\ActingUser;

final class ListEventRegistrationsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
    ) {}
}
