<?php

declare(strict_types=1);

namespace Daems\Application\Event\ListEvents;

final class ListEventsInput
{
    public function __construct(
        public readonly ?string $type = null,
    ) {}
}
