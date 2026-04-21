<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumPostsForAdmin;

use Daems\Domain\Forum\ForumPost;

final class ListForumPostsForAdminOutput
{
    /**
     * @param list<ForumPost> $posts
     */
    public function __construct(public readonly array $posts) {}
}
