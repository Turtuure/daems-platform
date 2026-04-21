<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlProjectRepositoryI18nTest extends MigrationTestCase
{
    private SqlProjectRepository $repo;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(53);
        $this->repo = new SqlProjectRepository(new Connection([
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

    public function testSaveAndFindWithFullTranslations(): void
    {
        $id = ProjectId::generate();
        $translations = new TranslationMap([
            'fi_FI' => ['title' => 'Projekti', 'summary' => 'Tiivistelmä', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Project',  'summary' => 'Summary',     'description' => 'Desc'],
        ]);
        $project = new Project(
            $id,
            $this->tenantId,
            'proj-slug-' . substr($id->value(), 0, 8),
            'Projekti',
            'community',
            'bi-folder',
            'Tiivistelmä',
            'Kuvaus',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            $translations,
        );
        $this->repo->save($project);

        $loaded = $this->repo->findByIdForTenant($id->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Projekti', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));

        $viewEn = $loaded->view(SupportedLocale::fromString('en_GB'), SupportedLocale::contentFallback());
        $this->assertSame('Project', $viewEn->field('title'));
    }

    public function testFindReturnsFallbackViewWhenRequestedLocaleMissing(): void
    {
        $id = ProjectId::generate();
        $translations = new TranslationMap([
            'en_GB' => ['title' => 'Project', 'summary' => 'Summary', 'description' => 'Desc'],
        ]);
        $project = new Project(
            $id,
            $this->tenantId,
            'proj-fb-' . substr($id->value(), 0, 8),
            'Project',
            'research',
            'bi-book',
            'Summary',
            'Desc',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            $translations,
        );
        $this->repo->save($project);

        $loaded = $this->repo->findByIdForTenant($id->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Project', $view->field('title'));
        $this->assertTrue($view->isFallback('title'));
    }

    public function testSaveTranslationUpserts(): void
    {
        $id = ProjectId::generate();
        $project = new Project(
            $id,
            $this->tenantId,
            'proj-ups-' . substr($id->value(), 0, 8),
            'Alku',
            'events',
            'bi-calendar',
            'S',
            'K',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            new TranslationMap([
                'fi_FI' => ['title' => 'Alku', 'summary' => 'S', 'description' => 'K'],
            ]),
        );
        $this->repo->save($project);

        $this->repo->saveTranslation(
            $this->tenantId,
            $id->value(),
            SupportedLocale::fromString('en_GB'),
            ['title' => 'Updated', 'summary' => 'S2', 'description' => 'D2'],
        );

        $loaded = $this->repo->findByIdForTenant($id->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $en = $loaded->translations()->rowFor(SupportedLocale::fromString('en_GB'));
        $this->assertIsArray($en);
        $this->assertSame('Updated', $en['title']);
    }
}
