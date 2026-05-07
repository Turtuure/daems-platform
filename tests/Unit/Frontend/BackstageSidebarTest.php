<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Frontend;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Frontend\BackstageSidebar;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BackstageSidebarTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-0000000000aa';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @param list<array{name: string, isCore?: bool, defaultAvailable?: bool, dependsOn?: list<string>, category?: ?string, nameKey?: ?string, descriptionKey?: ?string, sidebar?: ?array{group: string, order: int, icon: string, href: string}}> $specs
     */
    private function makeRegistry(array $specs): ModuleRegistry
    {
        [$registry, $root] = ModuleRegistryFactory::build($specs);
        $this->tempDirs[] = $root;
        return $registry;
    }

    private function makeTenant(): Tenant
    {
        return new Tenant(
            id: TenantId::fromString(self::TENANT_ID),
            slug: TenantSlug::fromString('acme'),
            name: 'Acme',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private function makeUser(bool $isPlatformAdmin): User
    {
        return new User(
            id: UserId::fromString(self::USER_ID),
            name: 'Test User',
            email: 'user@example.com',
            passwordHash: null,
            dateOfBirth: null,
            isPlatformAdmin: $isPlatformAdmin,
        );
    }

    private function makeRow(string $slug, ?DateTimeImmutable $av, ?DateTimeImmutable $en): TenantModule
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        return new TenantModule(
            id: '01958000-0000-7000-8000-' . substr(md5($slug), 0, 12),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: $slug,
            availableAt: $av,
            availableBy: $av !== null ? UserId::fromString(self::USER_ID) : null,
            enabledAt: $en,
            enabledBy: $en !== null ? UserId::fromString(self::USER_ID) : null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeResolver(ModuleRegistry $registry, InMemoryTenantModulesRepository $repo): TenantModuleResolver
    {
        return new TenantModuleResolver($registry, $repo);
    }

    private function makeRepo(): InMemoryTenantModulesRepository
    {
        return new InMemoryTenantModulesRepository();
    }

    public function testRendersDashboardSearchSettingsForAnyUser(): void
    {
        $registry = $this->makeRegistry([]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));

        $hrefs = array_map(static fn(array $i) => $i['href'], $items);
        $this->assertContains('/backstage/', $hrefs);
        $this->assertContains('/backstage/search', $hrefs);
        $this->assertContains('/backstage/settings', $hrefs);
        // No platform group for non-admin.
        $platformGroups = array_filter($items, static fn(array $i) => $i['group'] === 'platform');
        $this->assertCount(0, $platformGroups);
    }

    public function testAddsPlatformGroupOnlyForPlatformAdmin(): void
    {
        $registry = $this->makeRegistry([]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(true));

        $platform = array_values(array_filter($items, static fn(array $i) => $i['group'] === 'platform'));
        $this->assertCount(1, $platform);
        $this->assertSame('/backstage/platform/tenants', $platform[0]['href']);
        $this->assertSame('platform.tenants.title', $platform[0]['label_key']);
    }

    public function testExcludesModulesWithDisabledOrAvailableNotEnabledState(): void
    {
        $registry = $this->makeRegistry([
            [
                'name' => 'forum', 'isCore' => false,
                'sidebar' => ['group' => 'community', 'order' => 10, 'icon' => 'message-square', 'href' => '/backstage/forum'],
            ],
            [
                'name' => 'events', 'isCore' => false,
                'sidebar' => ['group' => 'content', 'order' => 5, 'icon' => 'calendar', 'href' => '/backstage/events'],
            ],
        ]);
        $repo = $this->makeRepo();
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        // forum: available but not enabled. events: nothing — DISABLED.
        $repo->save($this->makeRow('forum', av: $av, en: null));
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));

        $hrefs = array_map(static fn(array $i) => $i['href'], $items);
        $this->assertNotContains('/backstage/forum', $hrefs);
        $this->assertNotContains('/backstage/events', $hrefs);
    }

    public function testIncludesEnabledAndCoreModulesWithSidebarEntry(): void
    {
        $registry = $this->makeRegistry([
            [
                'name' => 'users', 'isCore' => true,
                'sidebar' => ['group' => 'members', 'order' => 0, 'icon' => 'users', 'href' => '/backstage/members'],
            ],
            [
                'name' => 'forum', 'isCore' => false,
                'sidebar' => ['group' => 'community', 'order' => 10, 'icon' => 'message-square', 'href' => '/backstage/forum'],
            ],
        ]);
        $repo = $this->makeRepo();
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $repo->save($this->makeRow('forum', av: $av, en: $en));
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));

        $hrefs = array_map(static fn(array $i) => $i['href'], $items);
        $this->assertContains('/backstage/members', $hrefs);
        $this->assertContains('/backstage/forum', $hrefs);
    }

    public function testOrdersByGroupRankThenIntraGroupOrder(): void
    {
        $registry = $this->makeRegistry([
            // members.order=10 should come AFTER members.order=0 inside the same group
            [
                'name' => 'users', 'isCore' => true,
                'sidebar' => ['group' => 'members', 'order' => 0, 'icon' => 'users', 'href' => '/backstage/members'],
            ],
            // community module — group rank 4, after members (rank 2) and content (rank 3)
            [
                'name' => 'forum', 'isCore' => true,
                'sidebar' => ['group' => 'community', 'order' => 0, 'icon' => 'message-square', 'href' => '/backstage/forum'],
            ],
            // content module — between members and community
            [
                'name' => 'events', 'isCore' => true,
                'sidebar' => ['group' => 'content', 'order' => 0, 'icon' => 'calendar', 'href' => '/backstage/events'],
            ],
        ]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));
        $hrefs = array_values(array_map(static fn(array $i) => $i['href'], $items));

        // Expected ordering:
        //   shell: dashboard(0), search(1)
        //   members: /backstage/members (rank 2, order 0)
        //   content: /backstage/events  (rank 3, order 0)
        //   community: /backstage/forum (rank 4, order 0)
        //   shell: settings (rank 0, order 999) — comes BEFORE module groups because rank=0 wins.
        //
        // i.e. all shell items group together at the top because their rank=0
        // is lower than any module group; settings (order=999) appears last
        // within the shell group but still before any rank>=2 group.
        $this->assertSame([
            '/backstage/',
            '/backstage/search',
            '/backstage/settings',
            '/backstage/members',
            '/backstage/events',
            '/backstage/forum',
        ], $hrefs);
    }

    public function testIntraGroupOrderRespected(): void
    {
        // Two modules in the same group — order numbers decide.
        $registry = $this->makeRegistry([
            [
                'name' => 'forum', 'isCore' => true,
                'sidebar' => ['group' => 'community', 'order' => 20, 'icon' => 'message-square', 'href' => '/backstage/forum'],
            ],
            [
                'name' => 'projects', 'isCore' => true,
                'sidebar' => ['group' => 'community', 'order' => 5, 'icon' => 'folder', 'href' => '/backstage/projects'],
            ],
        ]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));
        $communityHrefs = array_values(array_map(
            static fn(array $i) => $i['href'],
            array_filter($items, static fn(array $i) => $i['group'] === 'community'),
        ));

        $this->assertSame(['/backstage/projects', '/backstage/forum'], $communityHrefs);
    }

    public function testHeadlessModulesProduceNoSidebarItem(): void
    {
        // Module is enabled but its catalog entry has sidebar=null.
        $registry = $this->makeRegistry([
            [
                'name' => 'webhook', 'isCore' => true,
                'sidebar' => null,
            ],
        ]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));
        $hrefs = array_map(static fn(array $i) => $i['href'], $items);
        $this->assertNotContains('/backstage/webhook', $hrefs);
        // Only the 3 shell items remain.
        $this->assertCount(3, $items);
    }

    public function testModuleNameKeyFallsBackToConventionalKey(): void
    {
        $registry = $this->makeRegistry([
            [
                'name' => 'forum', 'isCore' => true,
                // no nameKey provided — should fall back to "modules.forum.name"
                'sidebar' => ['group' => 'community', 'order' => 0, 'icon' => 'message-square', 'href' => '/backstage/forum'],
            ],
        ]);
        $repo = $this->makeRepo();
        $sidebar = new BackstageSidebar($registry, $this->makeResolver($registry, $repo));

        $items = $sidebar->buildFor($this->makeTenant(), $this->makeUser(false));
        $forum = array_values(array_filter($items, static fn(array $i) => $i['href'] === '/backstage/forum'));
        $this->assertCount(1, $forum);
        $this->assertSame('modules.forum.name', $forum[0]['label_key']);
    }
}
