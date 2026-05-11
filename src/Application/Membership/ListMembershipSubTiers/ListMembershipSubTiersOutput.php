<?php
declare(strict_types=1);

namespace Daems\Application\Membership\ListMembershipSubTiers;

use Daems\Domain\Membership\TenantMembershipSubTier;

final class ListMembershipSubTiersOutput
{
    /** @param list<TenantMembershipSubTier> $items */
    public function __construct(public readonly array $items) {}
}
