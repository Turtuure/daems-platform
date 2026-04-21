<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumTopicsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumRepositoryInterface;

/**
 * Thin admin-side use case: list recent forum topics for the acting tenant.
 *
 * Authorization: acting user must be admin in the active tenant or a platform admin.
 * Delegates filtering and ordering to the repository.
 */
final class ListForumTopicsForAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListForumTopicsForAdminInput $in): ListForumTopicsForAdminOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        /** @var list<\Daems\Domain\Forum\ForumTopic> $topics */
        $topics = array_values($this->forum->listRecentTopicsForTenant($tenantId, $in->limit, $in->filters));

        return new ListForumTopicsForAdminOutput($topics);
    }
}
