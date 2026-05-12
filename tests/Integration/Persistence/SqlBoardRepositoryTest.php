<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlBoardRepositoryTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);
        // seed a tenant + user (FK targets)
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('01958000-0000-7000-8000-aaaaaaaaaaaa', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin)
                          VALUES ('01958000-0000-7000-8000-bbbbbbbbbbbb', 'gsa', 'gsa@test', '1990-01-01', 1)");
    }

    public function test_save_and_find_for_tenant(): void
    {
        $repo  = new SqlBoardRepository($this->pdo);
        $board = new Board(
            id:                   BoardId::fromString('01958000-0000-7000-8000-cccccccccccc'),
            tenantId:             TenantId::fromString('01958000-0000-7000-8000-aaaaaaaaaaaa'),
            bootstrappedByUserId: UserId::fromString('01958000-0000-7000-8000-bbbbbbbbbbbb'),
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12 10:00:00'),
            createdAt:            new \DateTimeImmutable('2026-05-12 10:00:00'),
        );
        $repo->save($board);

        $found = $repo->findForTenant(TenantId::fromString('01958000-0000-7000-8000-aaaaaaaaaaaa'));
        $this->assertNotNull($found);
        $this->assertSame('01958000-0000-7000-8000-cccccccccccc', $found->id->value());
    }

    public function test_find_returns_null_for_unseeded_tenant(): void
    {
        $repo = new SqlBoardRepository($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('01958000-0000-7000-8000-dddddddddddd', 'uu', 'UU')");
        $this->assertNull(
            $repo->findForTenant(TenantId::fromString('01958000-0000-7000-8000-dddddddddddd'))
        );
    }
}
