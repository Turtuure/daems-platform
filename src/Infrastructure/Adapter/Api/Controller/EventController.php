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
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
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
        $tenantId = $this->requireTenant($request)->id;
        $type = $request->string('type');
        $output = $this->listEvents->execute(new ListEventsInput($tenantId, $type ?: null));
        return Response::json(['data' => $output->events]);
    }

    public function show(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $userId = $request->string('user_id') ?: null;
        $output = $this->getEvent->execute(new GetEventInput($tenantId, $params['slug'], $userId));

        if ($output->event === null) {
            return Response::notFound('Event not found');
        }

        return Response::json(['data' => $output->event]);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }

    public function register(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();

        $output = $this->registerForEvent->execute(
            new RegisterForEventInput($acting, $params['slug']),
        );

        if ($output->error !== null && $output->error !== 'Already registered.') {
            return Response::notFound($output->error);
        }

        return Response::json(['data' => ['participant_count' => $output->participantCount]]);
    }

    public function unregister(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();

        $output = $this->unregisterFromEvent->execute(
            new UnregisterFromEventInput($acting, $params['slug']),
        );

        if ($output->error !== null) {
            return Response::notFound($output->error);
        }

        return Response::json(['data' => ['participant_count' => $output->participantCount]]);
    }
}
