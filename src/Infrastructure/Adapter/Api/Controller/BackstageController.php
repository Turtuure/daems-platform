<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal;
use Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposalInput;
use Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations;
use Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslationsInput;
use Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin;
use Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdminInput;
use Daems\Application\Backstage\RejectEventProposal\RejectEventProposal;
use Daems\Application\Backstage\RejectEventProposal\RejectEventProposalInput;
use Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation;
use Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslationInput;
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
use Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStatsInput;
use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats;
use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStatsInput;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStatsInput;
use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStatsInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplications;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdminInput;
use Daems\Application\Backstage\PublishEvent\PublishEvent;
use Daems\Application\Backstage\PublishEvent\PublishEventInput;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEventInput;
use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\InvalidLocaleException;
use Daems\Domain\Shared\ConflictException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use InvalidArgumentException;

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
        private readonly ListProposalsForAdmin $listProposals,
        private readonly ListMembersStats $listMembersStats,
        private readonly ListApplicationsStats $listApplicationsStats,
        private readonly ListEventsStats $listEventsStats,
        private readonly ListNotificationsStats $listNotificationsStats,
        private readonly GetEventWithAllTranslations $getEventWithAllTranslations,
        private readonly UpdateEventTranslation $updateEventTranslation,
        private readonly ListEventProposalsForAdmin $listEventProposals,
        private readonly ApproveEventProposal $approveEventProposal,
        private readonly RejectEventProposal $rejectEventProposal,
        private readonly UpdateTenantSettings $updateTenantSettings,
    ) {}

    public function updateTenantSettings(Request $request): Response
    {
        $actor = $request->requireActingUser();
        $rawPrefix = $request->input('member_number_prefix');
        $prefix = is_string($rawPrefix) ? $rawPrefix : null;

        $rawFmt = $request->input('default_time_format');
        $defaultTimeFormat = ($rawFmt === '12' || $rawFmt === '24') ? (string) $rawFmt : null;

        try {
            $out = $this->updateTenantSettings->execute(
                new UpdateTenantSettingsInput($actor, $prefix, $defaultTimeFormat),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }

        return Response::json(['data' => [
            'member_number_prefix' => $out->memberNumberPrefix,
            'default_time_format'  => $out->defaultTimeFormat,
        ]]);
    }

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

    public function listProposalsAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $out = $this->listProposals->execute(new ListProposalsForAdminInput($acting));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    public function statsMembers(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listMembersStats->execute(new ListMembersStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => $out->stats]);
    }

    public function statsApplications(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listApplicationsStats->execute(new ListApplicationsStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => $out->stats]);
    }

    public function statsEvents(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listEventsStats->execute(new ListEventsStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => $out->stats]);
    }

    public function statsNotifications(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listNotificationsStats->execute(new ListNotificationsStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => $out->stats]);
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

    // --- i18n: event translations ---

    /** @param array<string, string> $params */
    public function getEventWithTranslations(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $acting = $request->requireActingUser();
        $eventId = (string) ($params['id'] ?? '');

        try {
            $out = $this->getEventWithAllTranslations->execute(
                new GetEventWithAllTranslationsInput($tenantId, $eventId, $acting),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['data' => $out->event]);
    }

    /** @param array<string, string> $params */
    public function updateEventTranslation(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $acting = $request->requireActingUser();
        $eventId = (string) ($params['id'] ?? '');
        $localeRaw = (string) ($params['locale'] ?? '');
        $body = $request->all();

        try {
            $out = $this->updateEventTranslation->execute(
                new UpdateEventTranslationInput($tenantId, $eventId, $localeRaw, $body, $acting),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (InvalidLocaleException) {
            return Response::json(['error' => 'invalid_locale'], 400);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return Response::json(['data' => ['coverage' => $out->coverage]]);
    }

    // --- event proposals ---

    public function listEventProposals(Request $request): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $acting = $request->requireActingUser();
        $status = $request->string('status');

        try {
            $out = $this->listEventProposals->execute(
                new ListEventProposalsForAdminInput(
                    $tenantId,
                    $acting,
                    $status !== null && $status !== '' ? $status : null,
                ),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['data' => $out->proposals]);
    }

    /** @param array<string, string> $params */
    public function approveEventProposal(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $proposalId = (string) ($params['id'] ?? '');
        $body = $request->all();
        $note = is_string($body['note'] ?? null) ? (string) $body['note'] : null;

        try {
            $out = $this->approveEventProposal->execute(
                new ApproveEventProposalInput($acting, $proposalId, $note),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\Daems\Domain\Shared\ValidationException $e) {
            return Response::json(['error' => 'validation', 'details' => $e->fields()], 422);
        }
        return Response::json(['data' => ['event_id' => $out->eventId, 'slug' => $out->slug]], 201);
    }

    /** @param array<string, string> $params */
    public function rejectEventProposal(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $proposalId = (string) ($params['id'] ?? '');
        $body = $request->all();
        $note = is_string($body['note'] ?? null) ? (string) $body['note'] : null;

        try {
            $this->rejectEventProposal->execute(
                new RejectEventProposalInput($acting, $proposalId, $note),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\Daems\Domain\Shared\ValidationException $e) {
            return Response::json(['error' => 'validation', 'details' => $e->fields()], 422);
        }
        return Response::json(['data' => ['ok' => true]]);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }
}
