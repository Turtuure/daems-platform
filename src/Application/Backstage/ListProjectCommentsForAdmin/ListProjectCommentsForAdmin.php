<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectCommentsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjectCommentsForAdmin
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
    ) {}

    public function execute(ListProjectCommentsForAdminInput $input): ListProjectCommentsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $rows = $this->projects->listRecentCommentsForTenant($tenantId, $input->limit ?? 100);

        return new ListProjectCommentsForAdminOutput($rows);
    }
}
