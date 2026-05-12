<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface MemberSubTierAwardRepositoryInterface
{
    public function findActive(TenantId $tenantId, UserId $userId, \DateTimeImmutable $at): ?MemberSubTierAward;

    /** @return list<MemberSubTierAward> */
    public function listForUser(TenantId $tenantId, UserId $userId): array;

    /** @return list<MemberSubTierAward> */
    public function listActiveForSubTierSlug(TenantId $tenantId, string $subTierSlug, \DateTimeImmutable $at): array;

    public function save(MemberSubTierAward $award): void;
}
