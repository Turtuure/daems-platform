<?php

declare(strict_types=1);

namespace Daems\Application\Event\ListEventsForLocale;

use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;

final class ListEventsForLocale
{
    public function __construct(private readonly EventRepositoryInterface $events)
    {
    }

    public function execute(ListEventsForLocaleInput $input): ListEventsForLocaleOutput
    {
        $events = $this->events->listForTenant($input->tenantId, $input->type);
        $payload = [];
        foreach ($events as $event) {
            $view = $event->view($input->locale, SupportedLocale::contentFallback());
            $payload[] = array_merge(
                [
                    'id'         => $event->id()->value(),
                    'slug'       => $event->slug(),
                    'type'       => $event->type(),
                    'event_date' => $event->date(),
                    'event_time' => $event->time(),
                    'is_online'  => $event->online(),
                    'hero_image' => $event->heroImage(),
                    'status'     => $event->status(),
                ],
                $view->toApiPayload(),
            );
        }
        return new ListEventsForLocaleOutput($payload);
    }
}
