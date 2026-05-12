<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;

interface MemberExpulsionRepositoryInterface
{
    public function find(MemberExpulsionId $id): ?MemberExpulsion;

    public function findByDecisionId(BoardDecisionId $decisionId): ?MemberExpulsion;

    /** @return list<MemberExpulsion> */
    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array;

    public function save(MemberExpulsion $expulsion): void;
}
