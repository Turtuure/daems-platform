<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

interface TenantMembershipSubTierRepositoryInterface
{
    /** @return list<TenantMembershipSubTier> */
    public function listForTenant(TenantId $tenantId): array;

    public function findBySlug(
        TenantId $tenantId,
        MembershipType $appliesTo,
        string $slug,
    ): ?TenantMembershipSubTier;

    public function save(TenantMembershipSubTier $subTier): void;

    public function delete(TenantMembershipSubTierId $id): void;
}
