<?php

declare(strict_types=1);

namespace Daems\Application\Event\RegisterForEvent;

final class RegisterForEventInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userId,
    ) {}
}
