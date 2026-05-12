<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Audit;

use Daems\Application\Audit\GsaForceApproveBasic;
use Daems\Application\Audit\GsaForceApproveBasicInput;
use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryGsaOverrideRepository;
use PHPUnit\Framework\TestCase;

final class GsaForceApproveBasicTest extends TestCase
{
    public function test_rejects_short_reason(): void
    {
        $repo = new InMemoryGsaOverrideRepository();
        $uc = new GsaForceApproveBasic($repo, static fn() => null);
        $this->expectException(GsaOverrideRequiresReason::class);
        $uc->execute(new GsaForceApproveBasicInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason: 'too short',
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_happy_path_writes_audit_and_runs_approve(): void
    {
        $repo = new InMemoryGsaOverrideRepository();
        $called = false;
        $uc = new GsaForceApproveBasic($repo, function (string $id, \DateTimeImmutable $at, ?string $deleg) use (&$called) {
            $called = true;
        });
        $uc->execute(new GsaForceApproveBasicInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason: 'Test mode: bootstrap-tenant edge case requires direct approval.',
            at: new \DateTimeImmutable('2026-05-12'),
        ));
        $this->assertTrue($called);
    }

    public function test_writes_audit_row_visible_to_listForTenant(): void
    {
        $repo = new InMemoryGsaOverrideRepository();
        $uc = new GsaForceApproveBasic($repo, static fn() => null);
        $tenant = TenantId::generate();
        $uc->execute(new GsaForceApproveBasicInput(
            tenantId: $tenant,
            gsaUserId: UserId::generate(),
            applicationId: '01958000-0000-7000-8000-bbbbbbbbbbbb',
            reason: 'Override needed for bootstrap-tenant flow.',
            at: new \DateTimeImmutable('2026-05-12'),
        ));
        $this->assertCount(1, $repo->listForTenant($tenant));
    }
}
