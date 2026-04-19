<?php

declare(strict_types=1);

namespace Daems\Application\Event\RegisterForEvent;

use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;

final class RegisterForEvent
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
    ) {}

    public function execute(RegisterForEventInput $input): RegisterForEventOutput
    {
        $event = $this->events->findBySlugForTenant($input->slug, $input->acting->activeTenant);
        if ($event === null) {
            return new RegisterForEventOutput(0, 'Event not found.');
        }

        $eventId = $event->id()->value();
        $userId = $input->acting->id->value();

        if ($this->events->isRegistered($eventId, $userId)) {
            return new RegisterForEventOutput($this->events->countRegistrations($eventId), 'Already registered.');
        }

        $this->events->register(new EventRegistration(
            Uuid7::generate()->value(),
            $eventId,
            $userId,
            date('Y-m-d H:i:s'),
        ));

        return new RegisterForEventOutput($this->events->countRegistrations($eventId));
    }
}
