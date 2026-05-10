<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ResetUserLayout;

use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ResetUserLayout
{
    public function __construct(
        private readonly UserDashboardRepositoryInterface $repo,
    ) {}

    public function execute(UserId $userId, TenantId $tenantId): void
    {
        $this->repo->delete($userId, $tenantId);
    }
}
