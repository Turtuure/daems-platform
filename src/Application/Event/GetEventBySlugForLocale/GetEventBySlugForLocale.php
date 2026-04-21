<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEventBySlugForLocale;

use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;

final class GetEventBySlugForLocale
{
    public function __construct(private readonly EventRepositoryInterface $events)
    {
    }

    public function execute(GetEventBySlugForLocaleInput $input): GetEventBySlugForLocaleOutput
    {
        $event = $this->events->findBySlugForTenant($input->slug, $input->tenantId);
        if ($event === null) {
            return new GetEventBySlugForLocaleOutput(null);
        }
        $view = $event->view($input->locale, SupportedLocale::contentFallback());
        $payload = array_merge(
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
        return new GetEventBySlugForLocaleOutput($payload);
    }
}
