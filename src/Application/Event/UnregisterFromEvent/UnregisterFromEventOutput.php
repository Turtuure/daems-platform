<?php

declare(strict_types=1);

namespace Daems\Application\Event\UnregisterFromEvent;

final class UnregisterFromEventOutput
{
    public function __construct(
        public readonly int $participantCount,
        public readonly ?string $error = null,
    ) {}
}
