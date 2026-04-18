<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

final class EventRegistration
{
    public function __construct(
        private readonly string $id,
        private readonly string $eventId,
        private readonly string $userId,
        private readonly string $registeredAt,
    ) {}

    public function id(): string { return $this->id; }
    public function eventId(): string { return $this->eventId; }
    public function userId(): string { return $this->userId; }
    public function registeredAt(): string { return $this->registeredAt; }
}
