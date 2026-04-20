<?php

declare(strict_types=1);

namespace Daems\Domain\Dismissal;

final class AdminApplicationDismissal
{
    public function __construct(
        public readonly string $id,
        public readonly string $adminId,
        public readonly string $appId,
        public readonly string $appType, // 'member' | 'supporter'
        public readonly \DateTimeImmutable $dismissedAt,
    ) {}
}
