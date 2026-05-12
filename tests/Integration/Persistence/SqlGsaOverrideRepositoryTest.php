<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlGsaOverrideRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlGsaOverrideRepositoryTest extends MigrationTestCase
{
    private string $tenantId = '01958000-0000-7000-8000-aaaaaaaaaaaa';
    private string $gsaId    = '01958000-0000-7000-8000-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('{$this->tenantId}', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin) VALUES ('{$this->gsaId}', 'gsa', 'gsa@test', '1990-01-01', 1)");
    }

    public function test_save_and_list(): void
    {
        $repo = new SqlGsaOverrideRepository($this->pdo);
        $repo->save(new GsaOverride(
            id: GsaOverrideId::fromString('01958000-0000-7000-8000-cccccccccccc'),
            gsaUserId: UserId::fromString($this->gsaId),
            tenantId: TenantId::fromString($this->tenantId),
            action: GsaOverrideAction::ForceApproveBasic,
            targetId: '01958000-0000-7000-8000-ddddddddddd1',
            reason: 'Bootstrap test mode: tenant has no board yet.',
            performedAt: new \DateTimeImmutable('2026-05-12 10:00:00'),
        ));

        $list = $repo->listForTenant(TenantId::fromString($this->tenantId));
        $this->assertCount(1, $list);
        $this->assertSame('force_approve_basic', $list[0]->action->value);
    }
}
