<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSearchRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SearchIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $otherTenantId;
    private string $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);

        $this->conn = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->otherTenantId = Uuid7::generate()->value();
        $this->adminUserId = Uuid7::generate()->value();

        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantId, 'daems-st', 'Daems ST']);
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->otherTenantId, 'sahegroup-st', 'SaheGroup ST']);

        $this->seedEvents();
        $this->seedProjects();
        $this->seedInsights();
        $this->seedForum();
        $this->seedMembers();
    }

    private function seedEvents(): void
    {
        $pdo = $this->pdo();
        $e1 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e1, $this->tenantId, 'summer-2026', 'upcoming', 'published', '2026-06-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'fi_FI', 'Kesätapaaminen 2026', 'Helsinki', 'Vuoden tärkein tapahtuma']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'en_GB', 'Summer Meetup 2026', 'Helsinki', 'The highlight of the year']);

        $e2 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e2, $this->tenantId, 'draft-summer', 'upcoming', 'draft', '2026-07-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e2, 'fi_FI', 'Luonnos kesällä', '', '']);

        $e3 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e3, $this->otherTenantId, 'sahegroup-summer', 'upcoming', 'published', '2026-06-15']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e3, 'fi_FI', 'Summer Dar es Salaam', 'Dar es Salaam', 'SaheGroup event']);
    }

    private function seedProjects(): void
    {
        $pdo = $this->pdo();
        $p1 = Uuid7::generate()->value();
        $pdo->prepare("INSERT INTO projects (id, tenant_id, slug, status, category, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$p1, $this->tenantId, 'solar-roof', 'published', 'energy']);
        $pdo->prepare('INSERT INTO projects_i18n (project_id, locale, title, summary, description) VALUES (?,?,?,?,?)')
            ->execute([$p1, 'fi_FI', 'Aurinkokatto', 'Kerrostalon aurinkopaneelit', 'Tämä projekti asentaa paneeleita']);
    }

    private function seedInsights(): void
    {
        $pdo = $this->pdo();
        $i1 = Uuid7::generate()->value();
        $pdo->prepare("INSERT INTO insights
            (id, tenant_id, slug, title, category, category_label, featured, published_date, author, reading_time, excerpt, tags_json, content, search_text, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$i1, $this->tenantId, 'solar-future', 'Solar Future', 'tech', 'Tech', 0,
                '2026-01-15', 'Sam', 5, 'Solar panels are booming', '[]',
                '<p>Long form HTML <strong>solar</strong> content</p>',
                'Long form HTML solar content',
            ]);
        $i2 = Uuid7::generate()->value();
        $pdo->prepare("INSERT INTO insights
            (id, tenant_id, slug, title, category, category_label, featured, published_date, author, reading_time, excerpt, tags_json, content, search_text, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$i2, $this->tenantId, 'future-post', 'Future Solar Post', 'tech', 'Tech', 0,
                '2099-01-01', 'Sam', 3, 'x', '[]', 'body', 'body about solar']);
    }

    private function seedForum(): void
    {
        $pdo = $this->pdo();
        $catId = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$catId, $this->tenantId, 'general', 'General', 'chat', '', 1]);
        $topicId = Uuid7::generate()->value();
        $pdo->prepare("INSERT INTO forum_topics
            (id, tenant_id, category_id, slug, title, author_name, avatar_initials, avatar_color, first_post_search_text, last_activity_at, last_activity_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(),'anon',NOW())")
            ->execute([$topicId, $this->tenantId, $catId, 'welcome', 'Welcome to the forum',
                'anon', 'AN', 'blue', 'Please discuss climate action here']);
    }

    private function seedMembers(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare("INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, member_number, public_avatar_visible, membership_type, created_at)
                       VALUES (?,?,?,?,?,0,?,1,?,NOW())")
            ->execute([$this->adminUserId, 'Kimi Räikkönen', 'kimi@daems.local',
                password_hash('x', PASSWORD_BCRYPT), '1979-10-17', '000777', 'full']);
        $pdo->prepare("INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?,?,?,NOW())")
            ->execute([$this->adminUserId, $this->tenantId, 'member']);
    }

    public function test_members_search_is_admin_only(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        // includeUnpublished=true but actingUserIsAdmin=false → members branch should NOT run
        $nonAdmin = $repo->search($this->tenantId, 'Kimi', 'member', true, false, 5, 'fi_FI');
        self::assertCount(0, $nonAdmin);

        $admin = $repo->search($this->tenantId, 'Kimi', 'member', true, true, 5, 'fi_FI');
        self::assertCount(1, $admin);
        self::assertSame('member', $admin[0]->entityType);
    }

    public function test_members_search_matches_email(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'kimi@daems.local', 'member', true, true, 5, 'fi_FI');
        self::assertCount(1, $hits);
    }

    public function test_forum_body_match_returns_hit(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'climate', 'forum_topic', false, false, 5, 'fi_FI');
        self::assertCount(1, $hits);
        self::assertSame('forum_topic', $hits[0]->entityType);
    }

    public function test_finds_insight_by_search_text(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'solar', 'insight', false, false, 5, 'fi_FI');
        $titles = array_map(fn($h) => $h->title, $hits);
        self::assertContains('Solar Future', $titles);
        self::assertNotContains('Future Solar Post', $titles, 'Future-dated insights must be hidden in public search');
    }

    public function test_admin_sees_future_dated_insight(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'solar', 'insight', true, true, 5, 'fi_FI');
        $titles = array_map(fn($h) => $h->title, $hits);
        self::assertContains('Future Solar Post', $titles);
    }

    public function test_finds_project_in_current_locale(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'aurinkokatto', 'project', false, false, 5, 'fi_FI');
        self::assertCount(1, $hits);
        self::assertSame('project', $hits[0]->entityType);
    }

    public function test_finds_event_in_current_locale(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'kesätapaaminen', 'event', false, false, 5, 'fi_FI');
        self::assertCount(1, $hits);
        self::assertSame('Kesätapaaminen 2026', $hits[0]->title);
        self::assertNull($hits[0]->localeCode);
    }

    public function test_tenant_scope_isolates_results(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'Dar es Salaam', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $hits, 'Other-tenant events must not leak');
    }

    public function test_excludes_draft_events_for_public_but_returns_for_admin(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $public = $repo->search($this->tenantId, 'Luonnos', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $public);

        $admin = $repo->search($this->tenantId, 'Luonnos', 'event', true, true, 5, 'fi_FI');
        self::assertCount(1, $admin);
        self::assertSame('draft', $admin[0]->status);
    }
}
