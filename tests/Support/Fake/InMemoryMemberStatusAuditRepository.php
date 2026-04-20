<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;

final class InMemoryMemberStatusAuditRepository implements MemberStatusAuditRepositoryInterface
{
    /** @var list<MemberStatusAudit> */
    private array $rows = [];

    public function save(MemberStatusAudit $audit): void
    {
        $this->rows[] = $audit;
    }

    /** @return list<MemberStatusAudit> */
    public function allForTenant(string $tenantId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (MemberStatusAudit $a): bool => $a->tenantId === $tenantId,
        ));
    }
}
