<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class UserDashboard
{
    /** @param list<LayoutEntry> $layout */
    public function __construct(
        private readonly UserId $userId,
        private readonly TenantId $tenantId,
        private readonly array $layout,
        private readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function userId(): UserId { return $this->userId; }
    public function tenantId(): TenantId { return $this->tenantId; }
    /** @return list<LayoutEntry> */
    public function layout(): array { return $this->layout; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
