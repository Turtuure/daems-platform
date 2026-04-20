<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ArchiveEvent;

use Daems\Domain\Auth\ActingUser;

final class ArchiveEventInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
    ) {}
}
