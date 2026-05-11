<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InMemoryUserDashboardRepository implements UserDashboardRepositoryInterface
{
    /** @var array<string, UserDashboard> keyed by "userId|tenantId" */
    private array $rows = [];

    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard
    {
        return $this->rows[$this->key($userId, $tenantId)] ?? null;
    }

    public function save(UserDashboard $dashboard): void
    {
        $this->rows[$this->key($dashboard->userId(), $dashboard->tenantId())] = $dashboard;
    }

    public function delete(UserId $userId, TenantId $tenantId): void
    {
        unset($this->rows[$this->key($userId, $tenantId)]);
    }

    private function key(UserId $u, TenantId $t): string
    {
        return $u->value() . '|' . $t->value();
    }
}
