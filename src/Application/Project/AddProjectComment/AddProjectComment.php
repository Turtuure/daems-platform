<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectComment;

use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class AddProjectComment
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(AddProjectCommentInput $input): AddProjectCommentOutput
    {
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new AddProjectCommentOutput(null, 'Project not found.');
        }

        $now = date('Y-m-d H:i:s');
        $comment = new ProjectComment(
            ProjectCommentId::generate(),
            $project->id()->value(),
            $input->userId,
            $input->authorName,
            $input->avatarInitials,
            $input->avatarColor,
            $input->content,
            0,
            $now,
        );

        $this->projects->saveComment($comment);

        return new AddProjectCommentOutput([
            'id'              => $comment->id()->value(),
            'author'          => $comment->authorName(),
            'avatar_initials' => $comment->avatarInitials(),
            'avatar_color'    => $comment->avatarColor(),
            'content'         => htmlspecialchars($comment->content()),
            'likes'           => 0,
            'timestamp'       => date('F j, Y, H:i', strtotime($now)),
        ]);
    }
}
