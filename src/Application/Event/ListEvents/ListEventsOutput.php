<?php

declare(strict_types=1);

namespace Daems\Application\Event\ListEvents;

final class ListEventsOutput
{
    /** @param array<array<string, mixed>> $events */
    public function __construct(
        public readonly array $events,
    ) {}
}
