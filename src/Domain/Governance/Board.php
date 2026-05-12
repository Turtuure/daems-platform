<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class Board
{
    public function __construct(
        public readonly BoardId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $bootstrappedByUserId,
        public readonly \DateTimeImmutable $bootstrappedAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
