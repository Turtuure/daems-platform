<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumTopic;

use Daems\Application\Forum\Shared\ForumIdentityDeriver;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Domain\User\UserRepositoryInterface;

final class CreateForumTopic
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(CreateForumTopicInput $input): CreateForumTopicOutput
    {
        $category = $this->forum->findCategoryBySlugForTenant($input->categorySlug, $input->acting->activeTenant);

        if ($category === null) {
            return new CreateForumTopicOutput(null, 'Category not found.');
        }

        $identity = ForumIdentityDeriver::derive($input->acting, $this->users);

        $now  = date('Y-m-d H:i:s');
        $slug = $this->makeSlug($input->title);

        $topic = new ForumTopic(
            ForumTopicId::generate(),
            $input->acting->activeTenant,
            $category->id()->value(),
            $identity['user_id'],
            $slug,
            $input->title,
            $identity['author_name'],
            $identity['avatar_initials'],
            $identity['avatar_color'],
            false,
            0,
            0,
            $now,
            $identity['author_name'],
            $now,
        );

        $this->forum->saveTopic($topic);

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
