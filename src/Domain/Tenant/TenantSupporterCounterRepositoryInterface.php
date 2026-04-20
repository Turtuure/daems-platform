<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantSupporterCounterRepositoryInterface
{
    /**
     * Atomically allocate the next supporter number for the given tenant.
     * Implementations MUST perform this under a row lock that serialises
     * concurrent callers. Returned value is zero-padded to 5 digits.
     */
    public function allocateNext(string $tenantId): string;
}
