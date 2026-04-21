<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetEventWithAllTranslations;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class GetEventWithAllTranslations
{
    public function __construct(private readonly EventRepositoryInterface $events)
    {
    }

    public function execute(GetEventWithAllTranslationsInput $input): GetEventWithAllTranslationsOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $event = $this->events->findByIdForTenant($input->eventId, $input->tenantId);
        if ($event === null) {
            throw new NotFoundException('event');
        }

        $translations = [];
        foreach ($event->translations()->raw() as $loc => $row) {
            $translations[$loc] = $row;
        }
        $coverage = $event->translations()->coverage(Event::TRANSLATABLE_FIELDS);

        return new GetEventWithAllTranslationsOutput([
            'id'           => $event->id()->value(),
            'slug'         => $event->slug(),
            'type'         => $event->type(),
            'event_date'   => $event->date(),
            'event_time'   => $event->time(),
            'is_online'    => $event->online(),
            'hero_image'   => $event->heroImage(),
            'status'       => $event->status(),
            'translations' => $translations,
            'coverage'     => $coverage,
        ]);
    }
}
