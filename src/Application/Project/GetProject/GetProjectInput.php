<?php

declare(strict_types=1);

namespace Daems\Application\Project\GetProject;

use Daems\Domain\Tenant\TenantId;

final class GetProjectInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly ?string $userId = null,
    ) {}
}
