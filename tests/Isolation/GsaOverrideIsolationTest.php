<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlGsaOverrideRepository;

final class GsaOverrideIsolationTest extends IsolationTestCase
{
    public function test_overrides_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);

        $repo = new SqlGsaOverrideRepository($this->pdo());
        $repo->save(new GsaOverride(
            id: GsaOverrideId::generate(),
            gsaUserId: $gsa, tenantId: $daems,
            action: GsaOverrideAction::ForceApproveBasic,
            targetId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason: 'Bootstrap mode for daems tenant only.',
            performedAt: new \DateTimeImmutable('2026-05-12'),
        ));
        $repo->save(new GsaOverride(
            id: GsaOverrideId::generate(),
            gsaUserId: $gsa, tenantId: $sahe,
            action: GsaOverrideAction::ForceApproveBasic,
            targetId: '01958000-0000-7000-8000-bbbbbbbbbbbb',
            reason: 'Bootstrap mode for sahegroup tenant only.',
            performedAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $this->assertCount(1, $repo->listForTenant($daems));
        $this->assertCount(1, $repo->listForTenant($sahe));
    }
}
