<?php

declare(strict_types=1);

namespace Daems\Application\Event\UnregisterFromEvent;

final class UnregisterFromEventInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userId,
    ) {}
}
