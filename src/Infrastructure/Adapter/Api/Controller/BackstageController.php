<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\ArchiveEvent\ArchiveEvent;
use Daems\Application\Backstage\ArchiveEvent\ArchiveEventInput;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Application\Backstage\CreateEvent\CreateEvent;
use Daems\Application\Backstage\CreateEvent\CreateEventInput;
use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAudit;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAuditInput;
use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations;
use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrationsInput;
use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin;
use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdminInput;
use Daems\Application\Backstage\ListMembers\ListMembers;
use Daems\Application\Backstage\ListMembers\ListMembersInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplications;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Application\Backstage\PublishEvent\PublishEvent;
use Daems\Application\Backstage\PublishEvent\PublishEventInput;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEventInput;
use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BackstageController
{
    public function __construct(
        private readonly ListPendingApplications $listPending,
        private readonly DecideApplication $decide,
        private readonly ListMembers $listMembers,
        private readonly ChangeMemberStatus $changeStatus,
        private readonly GetMemberAudit $getAudit,
        private readonly ListPendingApplicationsForAdmin $listPendingForAdmin,
        private readonly DismissApplication $dismiss,
        private readonly ListEventsForAdmin $listEventsForAdmin,
        private readonly CreateEvent $createEvent,
        private readonly UpdateEvent $updateEvent,
        private readonly PublishEvent $publishEvent,
        private readonly ArchiveEvent $archiveEvent,
        private readonly ListEventRegistrations $listEventRegistrations,
        private readonly UnregisterUserFromEvent $unregisterUserFromEvent,
    ) {}

    public function pendingApplications(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $limitRaw = $request->query('limit');
        $limit  = is_numeric($limitRaw) ? (int) $limitRaw : 200;
        $limit  = max(1, min(500, $limit));

        try {
            $out = $this->listPending->execute(new ListPendingApplicationsInput($acting, $limit));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function decideApplication(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $type     = (string) ($params['type'] ?? '');
        $id       = (string) ($params['id'] ?? '');
        $decision = trim((string) ($request->string('decision') ?? ''));
        $noteRaw  = $request->string('note');
        $note     = ($noteRaw !== null && $noteRaw !== '') ? $noteRaw : null;

        try {
            $out = $this->decide->execute(new DecideApplicationInput($acting, $type, $id, $decision, $note));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (NotFoundException $e) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'fields' => $e->fields()], 422);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function members(Request $request): Response
    {
        $acting = $request->requireActingUser();

        /** @var array{status?:string, type?:string, q?:string} $filters */
        $filters = [];
        foreach (['status', 'type', 'q'] as $key) {
            $v = $request->string($key);
            if ($v !== null && $v !== '') {
                $filters[$key] = $v;
            }
        }

        $sort    = $request->string('sort') ?: 'member_number';
        $dir     = strtoupper($request->string('dir') ?: 'ASC');
        $pageRaw = $request->query('page');
        $perPageRaw = $request->query('per_page');
        $page    = is_numeric($pageRaw) ? (int) $pageRaw : 1;
        $perPage = is_numeric($perPageRaw) ? (int) $perPageRaw : 50;

        try {
            $out = $this->listMembers->execute(new ListMembersInput($acting, $filters, $sort, $dir, $page, $perPage));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        if ($request->query('export') === 'csv') {
            return $this->csvResponse($out->entries, $acting->activeTenant->value());
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function changeMemberStatus(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $memberId = (string) ($params['id'] ?? '');
        $status   = (string) ($request->string('status') ?? '');
        $reason   = (string) ($request->string('reason') ?? '');

        try {
            $out = $this->changeStatus->execute(new ChangeMemberStatusInput($acting, $memberId, $status, $reason));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'fields' => $e->fields()], 422);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function memberAudit(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $memberId = (string) ($params['id'] ?? '');
        $limitRaw = $request->query('limit');
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : 25;

        try {
            $out = $this->getAudit->execute(new GetMemberAuditInput($acting, $memberId, $limit));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function listPendingForAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();

        try {
            $out = $this->listPendingForAdmin->execute(new ListPendingApplicationsForAdminInput($acting));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function dismissApplication(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $type   = (string) ($params['type'] ?? '');
        $id     = (string) ($params['id'] ?? '');

        try {
            $this->dismiss->execute(new DismissApplicationInput($acting, $id, $type));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }

        return Response::json(null, 204);
    }

    public function listEvents(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $out = $this->listEventsForAdmin->execute(new ListEventsForAdminInput(
                $acting,
                $request->string('status'),
                $request->string('type'),
            ));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    public function createEvent(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $out = $this->createEvent->execute(new CreateEventInput(
                $acting,
                (string) $request->string('title'),
                (string) $request->string('type'),
                (string) $request->string('event_date'),
                $request->string('event_time'),
                $request->string('location'),
                (bool) $request->input('is_online'),
                (string) $request->string('description'),
                (bool) $request->input('publish_immediately'),
            ));
            return Response::json(['data' => $out->toArray()], 201);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    public function updateEvent(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $gallery = $request->input('gallery_json');
            $out = $this->updateEvent->execute(new UpdateEventInput(
                $acting, $id,
                $request->string('title'),
                $request->string('type'),
                $request->string('event_date'),
                $request->string('event_time'),
                $request->string('location'),
                $request->input('is_online') !== null ? (bool) $request->input('is_online') : null,
                $request->string('description'),
                $request->string('hero_image'),
                is_array($gallery) ? $gallery : null,
            ));
            return Response::json(['data' => $out->toArray()]);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    public function publishEvent(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $this->publishEvent->execute(new PublishEventInput($acting, $id));
            return Response::json(['data' => ['id' => $id, 'status' => 'published']]);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }

    public function archiveEvent(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $this->archiveEvent->execute(new ArchiveEventInput($acting, $id));
            return Response::json(['data' => ['id' => $id, 'status' => 'archived']]);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }

    public function listEventRegistrations(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $out = $this->listEventRegistrations->execute(new ListEventRegistrationsInput($acting, $id));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }

    public function removeEventRegistration(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $eventId = (string) ($params['id'] ?? '');
        $userId  = (string) ($params['user_id'] ?? '');
        try {
            $this->unregisterUserFromEvent->execute(new UnregisterUserFromEventInput($acting, $eventId, $userId));
            return Response::json(null, 204);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }

    /**
     * @param list<\Daems\Domain\Backstage\MemberDirectoryEntry> $entries
     */
    private function csvResponse(array $entries, string $tenantSlugOrId): Response
    {
        $filename = 'members-' . $tenantSlugOrId . '-' . date('Y-m-d') . '.csv';

        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return Response::json(['error' => 'csv_failed'], 500);
        }
        fputcsv($fh, ['member_number', 'name', 'email', 'membership_type', 'membership_status', 'role_in_tenant', 'joined_at']);
        foreach ($entries as $e) {
            fputcsv($fh, [$e->memberNumber ?? '', $e->name, $e->email, $e->membershipType, $e->membershipStatus, $e->roleInTenant ?? '', $e->joinedAt]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        if ($csv === false) {
            return Response::json(['error' => 'csv_failed'], 500);
        }

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
