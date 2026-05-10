<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface UserDashboardRepositoryInterface
{
    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard;

    public function save(UserDashboard $dashboard): void;

    public function delete(UserId $userId, TenantId $tenantId): void;
}
