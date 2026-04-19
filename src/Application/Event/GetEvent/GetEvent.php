<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEvent;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;

final class GetEvent
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
    ) {}

    public function execute(GetEventInput $input): GetEventOutput
    {
        $event = $this->events->findBySlugForTenant($input->slug, $input->tenantId);

        if ($event === null) {
            return new GetEventOutput(null);
        }

        $id    = $event->id()->value();
        $count = $this->events->countRegistrations($id);
        $isReg = $input->userId !== null
            ? $this->events->isRegistered($id, $input->userId)
            : false;

        return new GetEventOutput($this->toArray($event, $count, $isReg));
    }

    private function toArray(Event $e, int $participantCount, bool $isRegistered): array
    {
        return [
            'id'                 => $e->id()->value(),
            'slug'               => $e->slug(),
            'title'              => $e->title(),
            'type'               => $e->type(),
            'date'               => $e->date(),
            'time'               => $e->time(),
            'location'           => $e->location(),
            'online'             => $e->online(),
            'description'        => $e->description(),
            'hero_image'         => $e->heroImage(),
            'gallery'            => $e->gallery(),
            'participant_count'  => $participantCount,
            'is_registered'      => $isRegistered,
        ];
    }
}
