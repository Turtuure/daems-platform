<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEventBySlugForLocale;

final class GetEventBySlugForLocaleOutput
{
    /**
     * @param array<string, mixed>|null $event
     */
    public function __construct(public readonly ?array $event)
    {
    }
}
