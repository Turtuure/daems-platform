<?php

declare(strict_types=1);

namespace Daems\Application\Event\UnregisterFromEvent;

use Daems\Domain\Event\EventRepositoryInterface;

final class UnregisterFromEvent
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
    ) {}

    public function execute(UnregisterFromEventInput $input): UnregisterFromEventOutput
    {
        $event = $this->events->findBySlug($input->slug);
        if ($event === null) {
            return new UnregisterFromEventOutput(0, 'Event not found.');
        }

        $eventId = $event->id()->value();
        $this->events->unregister($eventId, $input->acting->id->value());

        return new UnregisterFromEventOutput($this->events->countRegistrations($eventId));
    }
}
