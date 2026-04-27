<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAudit;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAuditInput;
use Daems\Application\Backstage\ListMembers\ListMembers;
use Daems\Application\Backstage\ListMembers\ListMembersInput;
use Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStatsInput;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStatsInput;
use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStatsInput;
use Daems\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use Daems\Application\Backstage\ListDecidedApplications\ListDecidedApplicationsInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplications;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdminInput;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\Tenant;
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
        private readonly ListProposalsForAdmin $listProposals,
        private readonly ListMembersStats $listMembersStats,
        private readonly ListApplicationsStats $listApplicationsStats,
        private readonly ListNotificationsStats $listNotificationsStats,
        private readonly UpdateTenantSettings $updateTenantSettings,
        private readonly ListDecidedApplications $listDecided,
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

    public function decidedApplications(Request $request): Response
    {
        $acting = $request->requireActingUser();

        $decisionRaw = $request->query('decision');
        $decision = is_string($decisionRaw) ? $decisionRaw : 'rejected';
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return Response::json(['error' => 'validation_failed', 'message' => "decision must be 'approved' or 'rejected'"], 422);
        }

        $limitRaw = $request->query('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 200;
        $limit = max(1, min(500, $limit));

        $daysRaw = $request->query('days');
        $days = is_numeric($daysRaw) ? (int) $daysRaw : 30;
        $days = max(1, min(365, $days));

        try {
            $out = $this->listDecided->execute(new ListDecidedApplicationsInput($acting, $decision, $limit, $days));
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

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }
}
