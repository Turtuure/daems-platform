<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DateTimeImmutable;

final class Tenant
{
    public function __construct(
        public readonly TenantId $id,
        public readonly TenantSlug $slug,
        public readonly string $name,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $memberNumberPrefix = null,
    ) {}
}
