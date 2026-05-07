<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\CreateTenant;

use Daems\Domain\Tenant\TenantId;
use DateTimeImmutable;

final class CreateTenantOutput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
