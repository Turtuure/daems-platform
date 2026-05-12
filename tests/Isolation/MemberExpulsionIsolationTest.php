<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberExpulsionRepository;

final class MemberExpulsionIsolationTest extends IsolationTestCase
{
    public function test_expulsions_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $u1    = $this->seedUser('01958000-0000-7000-8000-ffffffffff01', 'u1@test');
        $u2    = $this->seedUser('01958000-0000-7000-8000-ffffffffff02', 'u2@test');
        $p1    = $this->seedUser('01958000-0000-7000-8000-ffffffffff03', 'p1@test');
        $p2    = $this->seedUser('01958000-0000-7000-8000-ffffffffff04', 'p2@test');

        $repo = new SqlMemberExpulsionRepository($this->pdo);
        $repo->save(new MemberExpulsion(
            id: MemberExpulsionId::generate(),
            tenantId: $daems, targetUserId: $u1, proposedByUserId: $p1,
            reason: 'reason A', hearingDeadlineAt: new \DateTimeImmutable('2026-06-01'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: null, expelledAt: null, appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));
        $repo->save(new MemberExpulsion(
            id: MemberExpulsionId::generate(),
            tenantId: $sahe, targetUserId: $u2, proposedByUserId: $p2,
            reason: 'reason B', hearingDeadlineAt: new \DateTimeImmutable('2026-06-01'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: null, expelledAt: null, appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $this->assertCount(1, $repo->listForTenant($daems));
        $this->assertCount(1, $repo->listForTenant($sahe));
    }
}
