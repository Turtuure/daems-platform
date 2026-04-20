<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantMemberCounterRepositoryInterface
{
    /**
     * Atomically allocate the next member number for the given tenant.
     * Implementations MUST perform this under a row lock that serialises
     * concurrent callers. Returned value is zero-padded to 5 digits.
     */
    public function allocateNext(string $tenantId): string;
}
