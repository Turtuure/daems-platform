<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumPost;

use Daems\Application\Forum\Shared\ForumIdentityDeriver;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class CreateForumPost
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(CreateForumPostInput $input): CreateForumPostOutput
    {
        $topic = $this->forum->findTopicBySlugForTenant($input->topicSlug, $input->acting->activeTenant);

        if ($topic === null) {
            return new CreateForumPostOutput(false, 'Thread not found.');
        }

        $identity = ForumIdentityDeriver::derive($input->acting, $this->users);
        $now = date('Y-m-d H:i:s');

        $post = new ForumPost(
            ForumPostId::generate(),
            $input->acting->activeTenant,
            $topic->id()->value(),
            $identity['user_id'],
            $identity['author_name'],
            $identity['avatar_initials'],
            $identity['avatar_color'],
            $identity['role'],
            $identity['role_class'],
            $identity['joined_text'],
            $input->content,
            0,
            $now,
            time(),
        );

        $this->forum->savePost($post);
        $this->forum->recordTopicReply($topic->id()->value(), $now, $identity['author_name']);

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
