<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumPost;

use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumRepositoryInterface;

final class CreateForumPost
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(CreateForumPostInput $input): CreateForumPostOutput
    {
        $topic = $this->forum->findTopicBySlug($input->topicSlug);

        if ($topic === null) {
            return new CreateForumPostOutput(false, 'Thread not found.');
        }

        $now = date('Y-m-d H:i:s');

        $post = new ForumPost(
            ForumPostId::generate(),
            $topic->id()->value(),
            $input->userId,
            $input->authorName,
            $input->avatarInitials,
            $input->avatarColor,
            $input->role,
            $input->roleClass,
            $input->joinedText,
            $input->content,
            0,
            $now,
            time(),
        );

        $this->forum->savePost($post);
        $this->forum->recordTopicReply($topic->id()->value(), $now, $input->authorName);

        return new CreateForumPostOutput(true, null, [
            'id'              => $post->id()->value(),
            'author_name'     => $post->authorName(),
            'avatar_initials' => $post->avatarInitials(),
            'avatar_color'    => $post->avatarColor(),
            'role'            => $post->role(),
            'role_class'      => $post->roleClass(),
            'joined_text'     => $post->joinedText(),
            'content'         => $post->content(),
            'likes'           => 0,
            'created_at'      => $post->createdAt(),
        ]);
    }
}
