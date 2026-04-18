<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEvent;

final class GetEventOutput
{
    /** @param array<string, mixed>|null $event */
    public function __construct(
        public readonly ?array $event,
    ) {}
}
