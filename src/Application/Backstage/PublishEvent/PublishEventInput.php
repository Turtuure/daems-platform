<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\PublishEvent;

use Daems\Domain\Auth\ActingUser;

final class PublishEventInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
    ) {}
}
