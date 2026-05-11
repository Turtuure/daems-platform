<?php
declare(strict_types=1);

namespace Daems\Application\Membership\ListMembershipSubTiers;

use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class ListMembershipSubTiers
{
    public function __construct(
        private readonly TenantMembershipSubTierRepositoryInterface $repo,
    ) {}

    public function execute(TenantId $tenantId): ListMembershipSubTiersOutput
    {
        return new ListMembershipSubTiersOutput($this->repo->listForTenant($tenantId));
    }
}
