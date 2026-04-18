<?php

declare(strict_types=1);

namespace Daems\Application\Project\GetProject;

use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;

final class GetProject
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
    ) {}

    public function execute(GetProjectInput $input): GetProjectOutput
    {
        $project = $this->projects->findBySlug($input->slug);

        if ($project === null) {
            return new GetProjectOutput(null);
        }

        $projectId      = $project->id()->value();
        $participantCount = $this->projects->countParticipants($projectId);
        $isParticipant  = $input->userId !== null
            ? $this->projects->isParticipant($projectId, $input->userId)
            : false;

        $comments = array_map(
            static fn(ProjectComment $c) => [
                'id'              => $c->id()->value(),
                'author'          => $c->authorName(),
                'avatar_initials' => $c->avatarInitials(),
                'avatar_color'    => $c->avatarColor(),
                'content'         => $c->content(),
                'likes'           => $c->likes(),
                'timestamp'       => date('F j, Y, H:i', strtotime($c->createdAt())),
            ],
            $this->projects->findCommentsByProjectId($projectId),
        );

        $updates = array_map(
            static fn(ProjectUpdate $u) => [
                'id'         => $u->id()->value(),
                'title'      => $u->title(),
                'content'    => $u->content(),
                'author'     => $u->authorName(),
                'created_at' => date('F j, Y', strtotime($u->createdAt())),
            ],
            $this->projects->findUpdatesByProjectId($projectId),
        );

        return new GetProjectOutput([
            'id'                => $projectId,
            'slug'              => $project->slug(),
            'title'             => $project->title(),
            'category'          => $project->category(),
            'icon'              => $project->icon(),
            'summary'           => $project->summary(),
            'description'       => $project->description(),
            'status'            => $project->status(),
            'sort_order'        => $project->sortOrder(),
            'participant_count' => $participantCount,
            'is_participant'    => $isParticipant,
            'comments'          => $comments,
            'updates'           => $updates,
        ]);
    }
}
