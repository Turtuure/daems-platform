<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumTopic;

use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;

final class CreateForumTopic
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(CreateForumTopicInput $input): CreateForumTopicOutput
    {
        $category = $this->forum->findCategoryBySlug($input->categorySlug);

        if ($category === null) {
            return new CreateForumTopicOutput(null, 'Category not found.');
        }

        $now  = date('Y-m-d H:i:s');
        $slug = $this->makeSlug($input->title);

        $topic = new ForumTopic(
            ForumTopicId::generate(),
            $category->id()->value(),
            $input->userId,
            $slug,
            $input->title,
            $input->authorName,
            $input->avatarInitials,
            $input->avatarColor,
            false,
            0,
            0,
            $now,
            $input->authorName,
            $now,
        );

        $this->forum->saveTopic($topic);

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
            1,
        );

        $this->forum->savePost($post);

        return new CreateForumTopicOutput($slug);
    }

    private function makeSlug(string $title): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
        return substr($slug, 0, 80) . '-' . substr(uniqid('', false), -6);
    }
}
