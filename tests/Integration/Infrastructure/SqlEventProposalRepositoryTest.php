<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlEventProposalRepositoryTest extends MigrationTestCase
{
    private SqlEventProposalRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(56);
        $this->repo = new SqlEventProposalRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute(['daems']);
        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('daems tenant missing');
        }
        $this->tenantId = TenantId::fromString($id);
    }

    public function testSaveAndFindById(): void
    {
        $id = EventProposalId::generate();

        $proposal = new EventProposal(
            $id,
            $this->tenantId,
            '01958000-0000-7000-8000-00000000aaaa',
            'Author',
            'a@x.fi',
            'Title',
            '2026-09-01',
            '18:00',
            'Helsinki',
            false,
            'Description',
            'fi_FI',
            'pending',
            date('Y-m-d H:i:s'),
        );
        $this->repo->save($proposal);

        $loaded = $this->repo->findByIdForTenant($id->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $this->assertSame('fi_FI', $loaded->sourceLocale());
        $this->assertSame('Title', $loaded->title());
    }

    public function testListFiltersByStatus(): void
    {
        $p1 = new EventProposal(
            EventProposalId::generate(),
            $this->tenantId,
            '01958000-0000-7000-8000-00000000aa01',
            'A',
            'a@x',
            'T1',
            '2026-09-01',
            null,
            null,
            true,
            'D',
            'en_GB',
            'pending',
            date('Y-m-d H:i:s'),
        );
        $p2 = new EventProposal(
            EventProposalId::generate(),
            $this->tenantId,
            '01958000-0000-7000-8000-00000000aa02',
            'B',
            'b@x',
            'T2',
            '2026-09-02',
            null,
            null,
            false,
            'D',
            'fi_FI',
            'approved',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            '01958000-0000-7000-8000-00000000bbbb',
            null,
        );
        $this->repo->save($p1);
        $this->repo->save($p2);

        $this->assertCount(1, $this->repo->listForTenant($this->tenantId, 'pending'));
        $this->assertCount(2, $this->repo->listForTenant($this->tenantId, null));
    }

    public function testRecordDecision(): void
    {
        $id = EventProposalId::generate();

        $p = new EventProposal(
            $id,
            $this->tenantId,
            '01958000-0000-7000-8000-00000000cccc',
            'A',
            'a@x',
            'T',
            '2026-09-01',
            null,
            null,
            false,
            'D',
            'sw_TZ',
            'pending',
            date('Y-m-d H:i:s'),
        );
        $this->repo->save($p);

        $this->repo->recordDecision(
            $id->value(),
            $this->tenantId,
            'approved',
            '01958000-0000-7000-8000-00000000dddd',
            'OK',
            new \DateTimeImmutable('now'),
        );
        $loaded = $this->repo->findByIdForTenant($id->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $this->assertSame('approved', $loaded->status());
        $this->assertSame('01958000-0000-7000-8000-00000000dddd', $loaded->decidedBy());
    }
}
