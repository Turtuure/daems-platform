<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DateTimeImmutable;

final readonly class Tenant
{
    public function __construct(
        public TenantId $id,
        public TenantSlug $slug,
        public string $name,
        public DateTimeImmutable $createdAt,
    ) {}
}
