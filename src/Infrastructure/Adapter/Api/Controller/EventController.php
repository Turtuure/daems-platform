<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Event\GetEvent\GetEvent;
use Daems\Application\Event\GetEvent\GetEventInput;
use Daems\Application\Event\ListEvents\ListEvents;
use Daems\Application\Event\ListEvents\ListEventsInput;
use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\RegisterForEvent\RegisterForEventInput;
use Daems\Application\Event\UnregisterFromEvent\UnregisterFromEvent;
use Daems\Application\Event\UnregisterFromEvent\UnregisterFromEventInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class EventController
{
    public function __construct(
        private readonly ListEvents $listEvents,
        private readonly GetEvent $getEvent,
        private readonly RegisterForEvent $registerForEvent,
        private readonly UnregisterFromEvent $unregisterFromEvent,
    ) {}

    public function index(Request $request): Response
    {
        $type = $request->query('type');
        $output = $this->listEvents->execute(new ListEventsInput($type ?: null));
        return Response::json(['data' => $output->events]);
    }

    public function show(Request $request, array $params): Response
    {
        $userId = $request->query('user_id') ?: null;
        $output = $this->getEvent->execute(new GetEventInput($params['slug'], $userId));

        if ($output->event === null) {
            return Response::notFound('Event not found');
        }

        return Response::json(['data' => $output->event]);
    }

    public function register(Request $request, array $params): Response
    {
        $userId = trim((string) $request->input('user_id'));
        if ($userId === '') {
            return Response::badRequest('user_id is required.');
        }

        $output = $this->registerForEvent->execute(
            new RegisterForEventInput($params['slug'], $userId),
        );

        if ($output->error !== null && $output->error !== 'Already registered.') {
            return Response::notFound($output->error);
        }

        return Response::json(['data' => ['participant_count' => $output->participantCount]]);
    }

    public function unregister(Request $request, array $params): Response
    {
        $userId = trim((string) $request->input('user_id'));
        if ($userId === '') {
            return Response::badRequest('user_id is required.');
        }

        $output = $this->unregisterFromEvent->execute(
            new UnregisterFromEventInput($params['slug'], $userId),
        );

        if ($output->error !== null) {
            return Response::notFound($output->error);
        }

        return Response::json(['data' => ['participant_count' => $output->participantCount]]);
    }
}
