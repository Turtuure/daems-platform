<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumThread;

use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumRepositoryInterface;

final class GetForumThread
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(GetForumThreadInput $input): GetForumThreadOutput
    {
        $topic = $this->forum->findTopicBySlug($input->topicSlug);

        if ($topic === null) {
            return new GetForumThreadOutput(null);
        }

        $category = $this->forum->findCategoryBySlug(
            $this->forum->findCategorySlugById($topic->categoryId()),
        );

        $posts = $this->forum->findPostsByTopic($topic->id()->value());

        return new GetForumThreadOutput([
            'topic' => [
                'id'          => $topic->id()->value(),
                'slug'        => $topic->slug(),
                'title'       => $topic->title(),
                'pinned'      => $topic->pinned(),
                'reply_count' => $topic->replyCount(),
                'view_count'  => $topic->viewCount(),
                'created_at'  => $topic->createdAt(),
            ],
            'category' => $category !== null ? [
                'slug' => $category->slug(),
                'name' => $category->name(),
            ] : ['slug' => '', 'name' => ''],
            'posts' => array_map(fn(ForumPost $p) => [
                'id'              => $p->id()->value(),
                'author_name'     => $p->authorName(),
                'avatar_initials' => $p->avatarInitials(),
                'avatar_color'    => $p->avatarColor(),
                'role'            => $p->role(),
                'role_class'      => $p->roleClass(),
                'joined_text'     => $p->joinedText(),
                'content'         => $p->content(),
                'likes'           => $p->likes(),
                'created_at'      => $p->createdAt(),
            ], $posts),
        ]);
    }
}
