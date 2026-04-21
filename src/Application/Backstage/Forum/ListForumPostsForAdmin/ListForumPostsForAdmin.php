<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumPostsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumRepositoryInterface;

/**
 * Thin admin-side use case: list recent forum posts for the acting tenant.
 *
 * Authorization: acting user must be admin in the active tenant or a platform admin.
 * Delegates filtering and ordering to the repository.
 */
final class ListForumPostsForAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListForumPostsForAdminInput $in): ListForumPostsForAdminOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        /** @var list<\Daems\Domain\Forum\ForumPost> $posts */
        $posts = array_values($this->forum->listRecentPostsForTenant($tenantId, $in->limit, $in->filters));

        return new ListForumPostsForAdminOutput($posts);
    }
}
