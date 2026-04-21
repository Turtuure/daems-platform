<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventProposalTenantIsolationTest extends IsolationTestCase
{
    private SqlEventProposalRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlEventProposalRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    public function test_tenant_b_cannot_read_tenant_a_event_proposals(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = EventProposalId::generate();
        $proposal = new EventProposal(
            $id,
            $tenantA,
            '01958000-0000-7000-8000-00000000aaaa',
            'Author',
            'a@daems.fi',
            'Tenant A event',
            '2026-09-01',
            null,
            null,
            false,
            'Description',
            'fi_FI',
            'pending',
            date('Y-m-d H:i:s'),
        );
        $this->repo->save($proposal);

        $this->assertNull($this->repo->findByIdForTenant($id->value(), $tenantB));
        $this->assertEmpty($this->repo->listForTenant($tenantB));
        $this->assertCount(1, $this->repo->listForTenant($tenantA));
    }

    public function test_record_decision_scoped_to_tenant(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = EventProposalId::generate();
        $this->repo->save(new EventProposal(
            $id,
            $tenantA,
            '01958000-0000-7000-8000-00000000aaaa',
            'Author',
            'a@x',
            'T',
            '2026-09-01',
            null,
            null,
            false,
            'D',
            'fi_FI',
            'pending',
            date('Y-m-d H:i:s'),
        ));

        // Recording a decision as tenant B should be a no-op (UPDATE affects 0 rows).
        $this->repo->recordDecision(
            $id->value(),
            $tenantB,
            'approved',
            '01958000-0000-7000-8000-00000000bbbb',
            null,
            new \DateTimeImmutable('now'),
        );

        $loaded = $this->repo->findByIdForTenant($id->value(), $tenantA);
        $this->assertNotNull($loaded);
        $this->assertSame('pending', $loaded->status());
    }
}
