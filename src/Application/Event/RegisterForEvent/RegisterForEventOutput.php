<?php

declare(strict_types=1);

namespace Daems\Application\Event\RegisterForEvent;

final class RegisterForEventOutput
{
    public function __construct(
        public readonly int $participantCount,
        public readonly ?string $error = null,
    ) {}
}
