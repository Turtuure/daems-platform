<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProjectInput;
use Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal;
use Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposalInput;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposalInput;
use Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations;
use Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslationsInput;
use Daems\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations;
use Daems\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslationsInput;
use Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin;
use Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdminInput;
use Daems\Application\Backstage\RejectEventProposal\RejectEventProposal;
use Daems\Application\Backstage\RejectEventProposal\RejectEventProposalInput;
use Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation;
use Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslationInput;
use Daems\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation;
use Daems\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslationInput;
use Daems\Application\Backstage\ArchiveEvent\ArchiveEvent;
use Daems\Application\Backstage\ArchiveEvent\ArchiveEventInput;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatusInput;
use Daems\Application\Backstage\CreateEvent\CreateEvent;
use Daems\Application\Backstage\CreateEvent\CreateEventInput;
use Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin;
use Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdminInput;
use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdminInput;
use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdminInput;
use Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdminInput;
use Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin;
use Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdminInput;
use Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin;
use Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdminInput;
use Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReport;
use Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReportInput;
use Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin;
use Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdminInput;
use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetailInput;
use Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin;
use Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdminInput;
use Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin;
use Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdminInput;
use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdminInput;
use Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin;
use Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdminInput;
use Daems\Application\Backstage\Forum\LockForumTopic\LockForumTopic;
use Daems\Application\Backstage\Forum\LockForumTopic\LockForumTopicInput;
use Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopic;
use Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopicInput;
use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDeleteInput;
use Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit;
use Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEditInput;
use Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock;
use Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLockInput;
use Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn;
use Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarnInput;
use Daems\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic;
use Daems\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopicInput;
use Daems\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic;
use Daems\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopicInput;
use Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdminInput;
use Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUser;
use Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUserInput;
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
use Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin;
use Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdminInput;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdminInput;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdminInput;
use Daems\Application\Backstage\PublishEvent\PublishEvent;
use Daems\Application\Backstage\PublishEvent\PublishEventInput;
use Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal;
use Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposalInput;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeaturedInput;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent;
use Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEventInput;
use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Application\Forum\ListForumCategories\ListForumCategories;
use Daems\Application\Forum\ListForumCategories\ListForumCategoriesInput;
use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Application\Insight\CreateInsight\CreateInsightInput;
use Daems\Application\Insight\DeleteInsight\DeleteInsight;
use Daems\Application\Insight\DeleteInsight\DeleteInsightInput;
use Daems\Application\Insight\ListInsights\ListInsights;
use Daems\Application\Insight\ListInsights\ListInsightsInput;
use Daems\Application\Insight\UpdateInsight\UpdateInsight;
use Daems\Application\Insight\UpdateInsight\UpdateInsightInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumTopic;
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
        private readonly ListProjectsForAdmin $listProjects,
        private readonly CreateProjectAsAdmin $createProject,
        private readonly AdminUpdateProject $updateProject,
        private readonly ChangeProjectStatus $changeProjectStatus,
        private readonly SetProjectFeatured $setProjectFeatured,
        private readonly ListProposalsForAdmin $listProposals,
        private readonly ApproveProjectProposal $approveProposal,
        private readonly RejectProjectProposal $rejectProposal,
        private readonly ListProjectCommentsForAdmin $listProjectComments,
        private readonly DeleteProjectCommentAsAdmin $deleteProjectComment,
        private readonly ListForumReportsForAdmin $listForumReports,
        private readonly GetForumReportDetail $getForumReport,
        private readonly ResolveForumReportByDelete $resolveByDelete,
        private readonly ResolveForumReportByLock $resolveByLock,
        private readonly ResolveForumReportByWarn $resolveByWarn,
        private readonly ResolveForumReportByEdit $resolveByEdit,
        private readonly DismissForumReport $dismissForumReport,
        private readonly ListForumTopicsForAdmin $listForumTopics,
        private readonly PinForumTopic $pinForumTopic,
        private readonly UnpinForumTopic $unpinForumTopic,
        private readonly LockForumTopic $lockForumTopic,
        private readonly UnlockForumTopic $unlockForumTopic,
        private readonly DeleteForumTopicAsAdmin $deleteForumTopic,
        private readonly ListForumPostsForAdmin $listForumPosts,
        private readonly EditForumPostAsAdmin $editForumPost,
        private readonly DeleteForumPostAsAdmin $deleteForumPost,
        private readonly WarnForumUser $warnForumUser,
        private readonly ListForumCategories $listForumCategories,
        private readonly CreateForumCategoryAsAdmin $createForumCategory,
        private readonly UpdateForumCategoryAsAdmin $updateForumCategory,
        private readonly DeleteForumCategoryAsAdmin $deleteForumCategory,
        private readonly ListForumModerationAuditForAdmin $listForumAudit,
        private readonly GetEventWithAllTranslations $getEventWithAllTranslations,
        private readonly UpdateEventTranslation $updateEventTranslation,
        private readonly GetProjectWithAllTranslations $getProjectWithAllTranslations,
        private readonly UpdateProjectTranslation $updateProjectTranslation,
        private readonly ListEventProposalsForAdmin $listEventProposals,
        private readonly ApproveEventProposal $approveEventProposal,
        private readonly RejectEventProposal $rejectEventProposal,
        private readonly UpdateTenantSettings $updateTenantSettings,
        private readonly CreateInsight $createInsight,
        private readonly UpdateInsight $updateInsight,
        private readonly DeleteInsight $deleteInsight,
        private readonly ListInsights $listInsights,
        private readonly InsightRepositoryInterface $insightRepo,
    ) {}

    public function updateTenantSettings(Request $request): Response
    {
        $actor = $request->requireActingUser();
        $rawPrefix = $request->input('member_number_prefix');
        $prefix = is_string($rawPrefix) ? $rawPrefix : null;

        try {
            $out = $this->updateTenantSettings->execute(
                new UpdateTenantSettingsInput($actor, $prefix),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }

        return Response::json(['data' => ['member_number_prefix' => $out->memberNumberPrefix]]);
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

    public function listProjectsAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $featuredRaw = $request->input('featured');
            $out = $this->listProjects->execute(new ListProjectsForAdminInput(
                $acting,
                $request->string('status'),
                $request->string('category'),
                $featuredRaw !== null ? (bool) $featuredRaw : null,
                $request->string('q'),
            ));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    public function createProjectAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $out = $this->createProject->execute(new CreateProjectAsAdminInput(
                $acting,
                (string) $request->string('title'),
                (string) $request->string('category'),
                $request->string('icon'),
                (string) $request->string('summary'),
                (string) $request->string('description'),
                $request->string('owner_id'),
            ));
            return Response::json(['data' => $out->toArray()], 201);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    /**
     * @param array<string,string> $params
     */
    public function updateProjectAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $sortOrderRaw = $request->input('sort_order');
            $out = $this->updateProject->execute(new AdminUpdateProjectInput(
                $acting, $id,
                $request->string('title'),
                $request->string('category'),
                $request->string('icon'),
                $request->string('summary'),
                $request->string('description'),
                is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : null,
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

    /**
     * @param array<string,string> $params
     */
    public function changeProjectStatus(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $status = (string) $request->string('status');
        try {
            $this->changeProjectStatus->execute(new ChangeProjectStatusInput($acting, $id, $status));
            return Response::json(['data' => ['id' => $id, 'status' => $status]]);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    /**
     * @param array<string,string> $params
     */
    public function setProjectFeatured(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $featured = (bool) $request->input('featured');
        try {
            $this->setProjectFeatured->execute(new SetProjectFeaturedInput($acting, $id, $featured));
            return Response::json(['data' => ['id' => $id, 'featured' => $featured]]);
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

    /**
     * @param array<string,string> $params
     */
    public function approveProposal(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $out = $this->approveProposal->execute(new ApproveProjectProposalInput(
                $acting, $id, $request->string('note'),
            ));
            return Response::json(['data' => $out->toArray()], 201);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    /**
     * @param array<string,string> $params
     */
    public function rejectProposal(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        try {
            $this->rejectProposal->execute(new RejectProjectProposalInput(
                $acting, $id, $request->string('note'),
            ));
            return Response::json(null, 204);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }
    }

    public function listProjectComments(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $limitRaw = $request->query('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : null;
        try {
            $out = $this->listProjectComments->execute(new ListProjectCommentsForAdminInput($acting, $limit));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    /**
     * @param array<string,string> $params
     */
    public function deleteProjectComment(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $projectId = (string) ($params['id'] ?? '');
        $commentId = (string) ($params['comment_id'] ?? '');
        try {
            $this->deleteProjectComment->execute(new DeleteProjectCommentAsAdminInput(
                $acting, $projectId, $commentId, $request->string('reason'),
            ));
            return Response::json(null, 204);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    // ---------------------------------------------------------------
    // Forum admin handlers (Task 21)
    // ---------------------------------------------------------------

    public function listForumReports(Request $request): Response
    {
        $acting = $request->requireActingUser();

        /** @var array{status?:string, target_type?:string} $filters */
        $filters = [];
        $status = $request->string('status');
        $targetType = $request->string('target_type');
        if (is_string($status) && $status !== '') {
            $filters['status'] = $status;
        }
        if (is_string($targetType) && $targetType !== '') {
            $filters['target_type'] = $targetType;
        }
        $limitRaw = $request->string('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 50;

        try {
            $out = $this->listForumReports->execute(new ListForumReportsForAdminInput($acting, $filters, $limit));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => array_map(static fn($a) => [
            'compound_id'   => $a->compoundKey(),
            'target_type'   => $a->targetType,
            'target_id'     => $a->targetId,
            'report_count'  => $a->reportCount,
            'reason_counts' => $a->reasonCounts,
            'earliest'      => $a->earliestCreatedAt,
            'latest'        => $a->latestCreatedAt,
            'status'        => $a->status,
        ], $out->items)]);
    }

    /**
     * @param array<string,string> $params
     */
    public function getForumReport(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $compoundId = (string) ($params['id'] ?? '');
        if (!preg_match('/^(post|topic):([0-9a-f\-]{36})$/', $compoundId, $m)) {
            return Response::badRequest('Invalid compound id');
        }
        $targetType = $m[1];
        $targetId = $m[2];

        try {
            $out = $this->getForumReport->execute(new GetForumReportDetailInput($acting, $targetType, $targetId));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Report not found');
        }

        $aggregated = $out->aggregated;
        return Response::json(['data' => [
            'aggregated' => [
                'compound_id'   => $aggregated->compoundKey(),
                'target_type'   => $aggregated->targetType,
                'target_id'     => $aggregated->targetId,
                'report_count'  => $aggregated->reportCount,
                'reason_counts' => $aggregated->reasonCounts,
                'earliest'      => $aggregated->earliestCreatedAt,
                'latest'        => $aggregated->latestCreatedAt,
                'status'        => $aggregated->status,
            ],
            'raw_reports' => array_map(static fn($r) => [
                'id'               => $r->id()->value(),
                'reporter_user_id' => $r->reporterUserId(),
                'reason_category'  => $r->reasonCategory(),
                'reason_detail'    => $r->reasonDetail(),
                'status'           => $r->status(),
                'created_at'       => $r->createdAt(),
            ], $out->rawReports),
            'target_content' => $out->targetContent,
        ]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function resolveForumReport(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $compoundId = (string) ($params['id'] ?? '');
        if (!preg_match('/^(post|topic):([0-9a-f\-]{36})$/', $compoundId, $m)) {
            return Response::badRequest('Invalid compound id');
        }
        $targetType = $m[1];
        $targetId = $m[2];
        $action = (string) ($request->string('action') ?? '');
        $note = $request->string('note');

        try {
            switch ($action) {
                case 'delete':
                    $this->resolveByDelete->execute(
                        new ResolveForumReportByDeleteInput($acting, $targetType, $targetId, $note),
                    );
                    break;
                case 'lock':
                    $this->resolveByLock->execute(
                        new ResolveForumReportByLockInput($acting, $targetType, $targetId, $note),
                    );
                    break;
                case 'warn':
                    $this->resolveByWarn->execute(
                        new ResolveForumReportByWarnInput($acting, $targetType, $targetId, $note),
                    );
                    break;
                case 'edit':
                    $newContent = (string) ($request->string('new_content') ?? '');
                    $this->resolveByEdit->execute(
                        new ResolveForumReportByEditInput($acting, $targetType, $targetId, $newContent, $note),
                    );
                    break;
                default:
                    return Response::badRequest('Unknown action');
            }
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Target not found');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        } catch (ConflictException $e) {
            return Response::conflict($e->getMessage());
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function dismissForumReport(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $compoundId = (string) ($params['id'] ?? '');
        if (!preg_match('/^(post|topic):([0-9a-f\-]{36})$/', $compoundId, $m)) {
            return Response::badRequest('Invalid compound id');
        }
        $targetType = $m[1];
        $targetId = $m[2];
        $note = $request->string('note');

        try {
            $this->dismissForumReport->execute(
                new DismissForumReportInput($acting, $targetType, $targetId, $note),
            );
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Report not found');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    public function listForumTopicsAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();

        $filters = [];
        foreach (['category_id', 'q', 'pinned_only', 'locked_only'] as $key) {
            $v = $request->input($key);
            if ($v !== null && $v !== '') {
                $filters[$key] = $v;
            }
        }
        $limitRaw = $request->string('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 100;

        try {
            $out = $this->listForumTopics->execute(new ListForumTopicsForAdminInput($acting, $limit, $filters));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => array_map(static fn(ForumTopic $t) => [
            'id'               => $t->id()->value(),
            'category_id'      => $t->categoryId(),
            'slug'             => $t->slug(),
            'title'            => $t->title(),
            'author_name'      => $t->authorName(),
            'user_id'          => $t->userId(),
            'pinned'           => $t->pinned(),
            'locked'           => $t->locked(),
            'reply_count'      => $t->replyCount(),
            'view_count'       => $t->viewCount(),
            'last_activity_at' => $t->lastActivityAt(),
            'created_at'       => $t->createdAt(),
        ], $out->topics)]);
    }

    /**
     * @param array<string,string> $params
     */
    public function pinForumTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->pinForumTopic->execute(new PinForumTopicInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Topic not found');
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id, 'pinned' => true]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function unpinForumTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->unpinForumTopic->execute(new UnpinForumTopicInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Topic not found');
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id, 'pinned' => false]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function lockForumTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->lockForumTopic->execute(new LockForumTopicInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Topic not found');
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id, 'locked' => true]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function unlockForumTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->unlockForumTopic->execute(new UnlockForumTopicInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Topic not found');
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id, 'locked' => false]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function deleteForumTopicAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->deleteForumTopic->execute(new DeleteForumTopicAsAdminInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Topic not found');
        }

        return Response::json(null, 204);
    }

    public function listForumPostsAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();

        $filters = [];
        foreach (['topic_id', 'q'] as $key) {
            $v = $request->input($key);
            if ($v !== null && $v !== '') {
                $filters[$key] = $v;
            }
        }
        $limitRaw = $request->string('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 100;

        try {
            $out = $this->listForumPosts->execute(new ListForumPostsForAdminInput($acting, $limit, $filters));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => array_map(static fn(ForumPost $p) => [
            'id'          => $p->id()->value(),
            'topic_id'    => $p->topicId(),
            'author_name' => $p->authorName(),
            'user_id'     => $p->userId(),
            'content'     => $p->content(),
            'likes'       => $p->likes(),
            'sort_order'  => $p->sortOrder(),
            'created_at'  => $p->createdAt(),
            'edited_at'   => $p->editedAt(),
        ], $out->posts)]);
    }

    /**
     * @param array<string,string> $params
     */
    public function editForumPostAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $newContent = (string) ($request->string('new_content') ?? '');
        $note = $request->string('note');

        try {
            $this->editForumPost->execute(new EditForumPostAsAdminInput($acting, $id, $newContent, $note));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Post not found');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function deleteForumPostAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->deleteForumPost->execute(new DeleteForumPostAsAdminInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Post not found');
        }

        return Response::json(null, 204);
    }

    /**
     * @param array<string,string> $params
     */
    public function warnForumUser(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $userId = (string) ($params['id'] ?? '');
        $reason = (string) ($request->string('reason') ?? '');

        try {
            $this->warnForumUser->execute(new WarnForumUserInput($acting, $userId, $reason));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('User not found');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        return Response::json(['data' => ['ok' => true, 'user_id' => $userId]], 201);
    }

    public function listForumCategoriesAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        if (!$acting->isAdminIn($acting->activeTenant) && !$acting->isPlatformAdmin) {
            return Response::forbidden('Admin only');
        }

        $out = $this->listForumCategories->execute(new ListForumCategoriesInput($acting->activeTenant));
        return Response::json(['data' => $out->categories]);
    }

    public function createForumCategoryAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $slug = (string) ($request->string('slug') ?? '');
        $name = (string) ($request->string('name') ?? '');
        $icon = (string) ($request->string('icon') ?? '');
        $description = (string) ($request->string('description') ?? '');
        $sortOrderRaw = $request->input('sort_order');
        $sortOrder = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : 0;

        try {
            $out = $this->createForumCategory->execute(new CreateForumCategoryAsAdminInput(
                $acting, $slug, $name, $icon, $description, $sortOrder,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        } catch (ConflictException $e) {
            return Response::conflict($e->getMessage());
        }

        return Response::json(['data' => ['id' => $out->id, 'slug' => $out->slug]], 201);
    }

    /**
     * @param array<string,string> $params
     */
    public function updateForumCategoryAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $sortOrderRaw = $request->input('sort_order');

        try {
            $this->updateForumCategory->execute(new UpdateForumCategoryAsAdminInput(
                $acting,
                $id,
                $request->string('slug'),
                $request->string('name'),
                $request->string('icon'),
                $request->string('description'),
                is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : null,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Category not found');
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        } catch (ConflictException $e) {
            return Response::conflict($e->getMessage());
        }

        return Response::json(['data' => ['ok' => true, 'id' => $id]]);
    }

    /**
     * @param array<string,string> $params
     */
    public function deleteForumCategoryAdmin(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');

        try {
            $this->deleteForumCategory->execute(new DeleteForumCategoryAsAdminInput($acting, $id));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        } catch (NotFoundException) {
            return Response::notFound('Category not found');
        } catch (ConflictException $e) {
            return Response::conflict($e->getMessage());
        }

        return Response::json(null, 204);
    }

    public function listForumAudit(Request $request): Response
    {
        $acting = $request->requireActingUser();

        /** @var array{action?:string, performer?:string} $filters */
        $filters = [];
        $action = $request->string('action');
        $performer = $request->string('performer');
        if (is_string($action) && $action !== '') {
            $filters['action'] = $action;
        }
        if (is_string($performer) && $performer !== '') {
            $filters['performer'] = $performer;
        }
        $limitRaw = $request->string('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 200;
        $offsetRaw = $request->string('offset');
        $offset = is_numeric($offsetRaw) ? max(0, (int) $offsetRaw) : 0;

        try {
            $out = $this->listForumAudit->execute(
                new ListForumModerationAuditForAdminInput($acting, $limit, $filters, $offset),
            );
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => array_map(static fn($e) => [
            'id'                => $e->id()->value(),
            'target_type'       => $e->targetType(),
            'target_id'         => $e->targetId(),
            'action'            => $e->action(),
            'original_payload'  => $e->originalPayload(),
            'new_payload'       => $e->newPayload(),
            'reason'            => $e->reason(),
            'performed_by'      => $e->performedBy(),
            'related_report_id' => $e->relatedReportId(),
            'created_at'        => $e->createdAt(),
        ], $out->entries)]);
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

    // --- i18n: project translations ---

    /** @param array<string, string> $params */
    public function getProjectWithTranslations(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $acting = $request->requireActingUser();
        $projectId = (string) ($params['id'] ?? '');

        try {
            $out = $this->getProjectWithAllTranslations->execute(
                new GetProjectWithAllTranslationsInput($tenantId, $projectId, $acting),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['data' => $out->project]);
    }

    /** @param array<string, string> $params */
    public function updateProjectTranslation(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $acting = $request->requireActingUser();
        $projectId = (string) ($params['id'] ?? '');
        $localeRaw = (string) ($params['locale'] ?? '');
        $body = $request->all();

        try {
            $out = $this->updateProjectTranslation->execute(
                new UpdateProjectTranslationInput($tenantId, $projectId, $localeRaw, $body, $acting),
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

    // --- insights admin ---

    public function listInsights(Request $request): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $category = $request->string('category');
        $out = $this->listInsights->execute(new ListInsightsInput(
            tenantId: $tenant->id,
            category: $category,
            includeUnpublished: true,
        ));

        return Response::json(['data' => $out->insights]);
    }

    public function createInsight(Request $request): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $body = $request->all();
        try {
            $out = $this->createInsight->execute(new CreateInsightInput(
                tenantId:      $tenant->id,
                slug:          self::bodyStr($body, 'slug'),
                title:         self::bodyStr($body, 'title'),
                category:      self::bodyStr($body, 'category'),
                categoryLabel: self::bodyStr($body, 'category_label', self::bodyStr($body, 'category')),
                featured:      self::bodyBool($body, 'featured'),
                publishedDate: self::bodyStr($body, 'published_date'),
                author:        self::bodyStr($body, 'author'),
                excerpt:       self::bodyStr($body, 'excerpt'),
                heroImage:     self::bodyNullableStr($body, 'hero_image'),
                tags:          self::bodyStringArray($body, 'tags'),
                content:       self::bodyStr($body, 'content'),
            ));
        } catch (ValidationException $e) {
            return Response::json([
                'error'  => 'validation',
                'errors' => $e->fields(),
            ], 422);
        }
        return Response::json(['data' => $this->insightArray($out->insight)], 201);
    }

    /** @param array<string, string> $params */
    public function getInsight(Request $request, array $params): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $insight = $this->insightRepo->findByIdForTenant(InsightId::fromString($id), $tenant->id);
        if ($insight === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['data' => $this->insightArray($insight)]);
    }

    /** @param array<string, string> $params */
    public function updateInsight(Request $request, array $params): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $body = $request->all();
        try {
            $out = $this->updateInsight->execute(new UpdateInsightInput(
                insightId:     InsightId::fromString($id),
                tenantId:      $tenant->id,
                slug:          self::bodyStr($body, 'slug'),
                title:         self::bodyStr($body, 'title'),
                category:      self::bodyStr($body, 'category'),
                categoryLabel: self::bodyStr($body, 'category_label', self::bodyStr($body, 'category')),
                featured:      self::bodyBool($body, 'featured'),
                publishedDate: self::bodyStr($body, 'published_date'),
                author:        self::bodyStr($body, 'author'),
                excerpt:       self::bodyStr($body, 'excerpt'),
                heroImage:     self::bodyNullableStr($body, 'hero_image'),
                tags:          self::bodyStringArray($body, 'tags'),
                content:       self::bodyStr($body, 'content'),
            ));
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation', 'errors' => $e->fields()], 422);
        }
        return Response::json(['data' => $this->insightArray($out->insight)]);
    }

    /** @param array<string, string> $params */
    public function deleteInsight(Request $request, array $params): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        try {
            $this->deleteInsight->execute(new DeleteInsightInput(
                insightId: InsightId::fromString($id),
                tenantId:  $tenant->id,
            ));
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['data' => ['deleted' => true]]);
    }

    private function requireInsightsAdmin(Request $request, Tenant $tenant): void
    {
        $actor = $request->requireActingUser();
        if (!$actor->isAdminIn($tenant->id)) {
            throw new ForbiddenException('forbidden');
        }
    }

    /** @return array<string, mixed> */
    private function insightArray(Insight $i): array
    {
        return [
            'id'             => $i->id()->value(),
            'slug'           => $i->slug(),
            'title'          => $i->title(),
            'category'       => $i->category(),
            'category_label' => $i->categoryLabel(),
            'featured'       => $i->featured(),
            'published_date' => $i->date(),
            'author'         => $i->author(),
            'reading_time'   => $i->readingTime(),
            'excerpt'        => $i->excerpt(),
            'hero_image'     => $i->heroImage(),
            'tags'           => $i->tags(),
            'content'        => $i->content(),
        ];
    }

    /** @param array<mixed> $body */
    private static function bodyStr(array $body, string $key, string $default = ''): string
    {
        $v = $body[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<mixed> $body */
    private static function bodyBool(array $body, string $key): bool
    {
        return (bool) ($body[$key] ?? false);
    }

    /** @param array<mixed> $body */
    private static function bodyNullableStr(array $body, string $key): ?string
    {
        $v = $body[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param array<mixed> $body
     * @return string[]
     */
    private static function bodyStringArray(array $body, string $key): array
    {
        $v = $body[$key] ?? null;
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_string'));
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
