<?php

declare(strict_types=1);

namespace Daems\Application\Event\ListEventsForLocale;

final class ListEventsForLocaleOutput
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(public readonly array $events)
    {
    }
}
