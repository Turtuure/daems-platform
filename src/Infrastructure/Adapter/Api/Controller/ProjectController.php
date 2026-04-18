<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Project\AddProjectComment\AddProjectComment;
use Daems\Application\Project\SubmitProjectProposal\SubmitProjectProposal;
use Daems\Application\Project\SubmitProjectProposal\SubmitProjectProposalInput;
use Daems\Application\Project\AddProjectComment\AddProjectCommentInput;
use Daems\Application\Project\AddProjectUpdate\AddProjectUpdate;
use Daems\Application\Project\AddProjectUpdate\AddProjectUpdateInput;
use Daems\Application\Project\ArchiveProject\ArchiveProject;
use Daems\Application\Project\ArchiveProject\ArchiveProjectInput;
use Daems\Application\Project\CreateProject\CreateProject;
use Daems\Application\Project\CreateProject\CreateProjectInput;
use Daems\Application\Project\GetProject\GetProject;
use Daems\Application\Project\GetProject\GetProjectInput;
use Daems\Application\Project\JoinProject\JoinProject;
use Daems\Application\Project\JoinProject\JoinProjectInput;
use Daems\Application\Project\LeaveProject\LeaveProject;
use Daems\Application\Project\LeaveProject\LeaveProjectInput;
use Daems\Application\Project\LikeProjectComment\LikeProjectComment;
use Daems\Application\Project\LikeProjectComment\LikeProjectCommentInput;
use Daems\Application\Project\ListProjects\ListProjects;
use Daems\Application\Project\ListProjects\ListProjectsInput;
use Daems\Application\Project\UpdateProject\UpdateProject;
use Daems\Application\Project\UpdateProject\UpdateProjectInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ProjectController
{
    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
        private readonly CreateProject $createProject,
        private readonly UpdateProject $updateProject,
        private readonly ArchiveProject $archiveProject,
        private readonly AddProjectComment $addComment,
        private readonly LikeProjectComment $likeComment,
        private readonly JoinProject $joinProject,
        private readonly LeaveProject $leaveProject,
        private readonly AddProjectUpdate $addUpdate,
        private readonly SubmitProjectProposal $submitProposal,
    ) {}

    public function index(Request $request): Response
    {
        $category = $request->query('category') ?: null;
        $status   = $request->query('status') ?: null;
        $search   = $request->query('search') ?: null;
        $output   = $this->listProjects->execute(new ListProjectsInput($category, $status, $search));
        return Response::json(['data' => $output->projects]);
    }

    public function show(Request $request, array $params): Response
    {
        $userId = $request->query('user_id') ?: null;
        $output = $this->getProject->execute(new GetProjectInput($params['slug'], $userId));

        if ($output->project === null) {
            return Response::notFound('Project not found');
        }

        return Response::json(['data' => $output->project]);
    }

    public function create(Request $request): Response
    {
        $body = $request->all();
        $output = $this->createProject->execute(new CreateProjectInput(
            trim($body['title'] ?? ''),
            trim($body['category'] ?? ''),
            trim($body['icon'] ?? 'bi-folder'),
            trim($body['summary'] ?? ''),
            trim($body['description'] ?? ''),
            trim($body['status'] ?? 'active'),
        ));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => $output->project], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $body = $request->all();
        $output = $this->updateProject->execute(new UpdateProjectInput(
            $params['slug'],
            trim($body['title'] ?? ''),
            trim($body['category'] ?? ''),
            trim($body['icon'] ?? 'bi-folder'),
            trim($body['summary'] ?? ''),
            trim($body['description'] ?? ''),
            trim($body['status'] ?? 'active'),
        ));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 404);
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    public function archive(Request $request, array $params): Response
    {
        $output = $this->archiveProject->execute(new ArchiveProjectInput($params['slug']));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 404);
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    public function addComment(Request $request, array $params): Response
    {
        $body = $request->all();
        $output = $this->addComment->execute(new AddProjectCommentInput(
            $params['slug'],
            trim($body['user_id'] ?? ''),
            trim($body['author_name'] ?? ''),
            trim($body['avatar_initials'] ?? ''),
            trim($body['avatar_color'] ?? ''),
            trim($body['content'] ?? ''),
        ));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => $output->comment], 201);
    }

    public function likeComment(Request $request, array $params): Response
    {
        $this->likeComment->execute(new LikeProjectCommentInput($params['id']));
        return Response::json(['data' => ['ok' => true]]);
    }

    public function join(Request $request, array $params): Response
    {
        $body   = $request->all();
        $output = $this->joinProject->execute(new JoinProjectInput(
            $params['slug'],
            trim($body['user_id'] ?? ''),
        ));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    public function leave(Request $request, array $params): Response
    {
        $body   = $request->all();
        $output = $this->leaveProject->execute(new LeaveProjectInput(
            $params['slug'],
            trim($body['user_id'] ?? ''),
        ));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => ['ok' => true]]);
    }

    public function addUpdate(Request $request, array $params): Response
    {
        $body   = $request->all();
        $output = $this->addUpdate->execute(new AddProjectUpdateInput(
            $params['slug'],
            trim($body['title'] ?? ''),
            trim($body['content'] ?? ''),
            trim($body['author_name'] ?? ''),
        ));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => ['ok' => true]], 201);
    }

    public function propose(Request $request): Response
    {
        $body   = $request->all();
        $output = $this->submitProposal->execute(new SubmitProjectProposalInput(
            trim($body['user_id'] ?? ''),
            trim($body['author_name'] ?? ''),
            trim($body['author_email'] ?? ''),
            trim($body['title'] ?? ''),
            trim($body['category'] ?? ''),
            trim($body['summary'] ?? ''),
            trim($body['description'] ?? ''),
        ));

        if (!$output->success) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => ['ok' => true]], 201);
    }
}
