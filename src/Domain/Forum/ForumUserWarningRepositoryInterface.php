<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumUserWarningRepositoryInterface
{
    public function record(ForumUserWarning $warning): void;

    /** @return list<ForumUserWarning> */
    public function listForUserForTenant(string $userId, TenantId $tenantId): array;
}
