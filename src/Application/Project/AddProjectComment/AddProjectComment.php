<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectComment;

use Daems\Application\Forum\Shared\ForumIdentityDeriver;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class AddProjectComment
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(AddProjectCommentInput $input): AddProjectCommentOutput
    {
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new AddProjectCommentOutput(null, 'Project not found.');
        }

        $user = $this->users->findById($input->acting->id->value());
        $authorName = $user !== null ? $user->name() : 'Unknown';
        $avatarInitials = ForumIdentityDeriver::initials($authorName);
        $avatarColor = '#64748b';

        $now = date('Y-m-d H:i:s');
        $comment = new ProjectComment(
            ProjectCommentId::generate(),
            $project->id()->value(),
            $input->acting->id->value(),
            $authorName,
            $avatarInitials,
            $avatarColor,
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
