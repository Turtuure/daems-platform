<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ProjectsI18nTenantIsolationTest extends IsolationTestCase
{
    private SqlProjectRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlProjectRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    public function test_tenant_b_cannot_read_tenant_a_project_translations(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = ProjectId::generate();
        $project = new Project(
            $id,
            $tenantA,
            'x-tenant-proj-' . substr($id->value(), 0, 8),
            'Secret',
            'community',
            'bi-folder',
            'S',
            'D',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            new TranslationMap([
                'fi_FI' => ['title' => 'Secret', 'summary' => 'S', 'description' => 'D'],
            ]),
        );
        $this->repo->save($project);

        $this->assertNull($this->repo->findByIdForTenant($id->value(), $tenantB));
    }

    public function test_tenant_b_cannot_write_translation_to_tenant_a_project(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = ProjectId::generate();
        $this->repo->save(new Project(
            $id,
            $tenantA,
            'secret-proj-' . substr($id->value(), 0, 8),
            'Secret',
            'research',
            'bi-book',
            'S',
            'D',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            new TranslationMap([
                'fi_FI' => ['title' => 'Secret', 'summary' => 'S', 'description' => 'D'],
            ]),
        ));

        $this->expectException(\DomainException::class);
        $this->repo->saveTranslation(
            $tenantB,
            $id->value(),
            SupportedLocale::fromString('en_GB'),
            ['title' => 'hack', 'summary' => 'hack', 'description' => 'hack'],
        );
    }
}
