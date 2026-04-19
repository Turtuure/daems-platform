<?php

declare(strict_types=1);

namespace Daems\Application\Event\ListEvents;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;

final class ListEvents
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
    ) {}

    public function execute(ListEventsInput $input): ListEventsOutput
    {
        $events = $this->events->listForTenant($input->tenantId, $input->type);

        return new ListEventsOutput(
            array_map(fn(Event $e) => $this->toArray($e), $events),
        );
    }

    private function toArray(Event $e): array
    {
        return [
            'id'          => $e->id()->value(),
            'slug'        => $e->slug(),
            'title'       => $e->title(),
            'type'        => $e->type(),
            'date'        => $e->date(),
            'time'        => $e->time(),
            'location'    => $e->location(),
            'online'      => $e->online(),
            'description' => $e->description(),
            'hero_image'  => $e->heroImage(),
            'gallery'     => $e->gallery(),
        ];
    }
}
