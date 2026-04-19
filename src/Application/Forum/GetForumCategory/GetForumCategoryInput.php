<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumCategory;

use Daems\Domain\Tenant\TenantId;

final class GetForumCategoryInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $slug,
    ) {}
}
