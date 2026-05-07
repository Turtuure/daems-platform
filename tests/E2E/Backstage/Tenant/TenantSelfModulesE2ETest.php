<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Tenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tenant-side enable/disable lever (Settings → Modules page).
 *
 * The controller resolves the "current tenant" from the request attribute
 * set by TenantContextMiddleware — which in this harness always returns
 * the seeded test tenant via the stub resolver. Cross-tenant attempts are
 * therefore expressed by giving the actor an admin role in some OTHER
 * tenant only; the request still hits the test-tenant context and the
 * controller's `requireTenantAdmin` 403s because the actor's role lookup
 * for the test tenant returns null.
 */
final class TenantSelfModulesE2ETest extends TestCase
{
    private KernelHarness $h;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function rebindRegistry(ModuleRegistry $registry): void
    {
        $this->h->container->bind(
            ModuleRegistry::class,
            static fn (): ModuleRegistry => $registry,
        );
    }

    private function seedRow(string $slug, ?DateTimeImmutable $enabledAt = null): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $tm = new TenantModule(
            id: TenantId::generate()->value(),
            tenantId: $this->h->testTenantId,
            moduleSlug: $slug,
            availableAt: $now,
            availableBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            enabledAt: $enabledAt,
            enabledBy: $enabledAt !== null ? UserId::fromString('01958000-0000-7000-8000-0000000000aa') : null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->h->tenantModules->save($tm);
    }

    private function adminToken(string $email = 'tenant-admin@x.com'): string
    {
        $u = $this->h->seedUser($email, 'pass1234', 'admin');
        return $this->h->tokenFor($u);
    }

    public function test_get_returns_three_buckets(): void
    {
        // Use real registry. Seed forum as enabled, projects as available
        // not enabled — events stays unseen (disabled bucket).
        $this->seedRow('forum', enabledAt: new DateTimeImmutable('2026-05-07T10:00:00+00:00'));
        $this->seedRow('projects', enabledAt: null);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant/modules', $this->adminToken());
        self::assertSame(200, $resp->status(), 'list body: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('enabled', $body['data']);
        self::assertArrayHasKey('availableNotEnabled', $body['data']);
        self::assertArrayHasKey('disabled', $body['data']);

        $enabledSlugs = array_column($body['data']['enabled'], 'slug');
        $availSlugs   = array_column($body['data']['availableNotEnabled'], 'slug');
        self::assertContains('forum', $enabledSlugs);
        self::assertContains('projects', $availSlugs);
    }

    public function test_enable_succeeds_when_dependencies_enabled(): void
    {
        [$registry, $tmp] = ModuleRegistryFactory::build([
            ['name' => 'mod-base', 'isCore' => false, 'defaultAvailable' => true],
            ['name' => 'mod-leaf', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-base']],
        ]);
        $this->tempDirs[] = $tmp;
        $this->rebindRegistry($registry);

        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $this->seedRow('mod-base', enabledAt: $now);
        $this->seedRow('mod-leaf', enabledAt: null);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/tenant/modules/mod-leaf/state',
            $this->adminToken(),
            ['action' => 'enable'],
        );
        self::assertSame(204, $resp->status(), 'enable body: ' . $resp->body());

        $row = $this->h->tenantModules->find($this->h->testTenantId, 'mod-leaf');
        self::assertNotNull($row);
        self::assertTrue($row->isEnabled());
    }

    public function test_enable_returns_422_when_dependency_not_enabled(): void
    {
        [$registry, $tmp] = ModuleRegistryFactory::build([
            ['name' => 'mod-base', 'isCore' => false, 'defaultAvailable' => true],
            ['name' => 'mod-leaf', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-base']],
        ]);
        $this->tempDirs[] = $tmp;
        $this->rebindRegistry($registry);

        // base is available but NOT enabled
        $this->seedRow('mod-base', enabledAt: null);
        $this->seedRow('mod-leaf', enabledAt: null);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/tenant/modules/mod-leaf/state',
            $this->adminToken(),
            ['action' => 'enable'],
        );
        self::assertSame(422, $resp->status(), 'enable body: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsString('mod-base', (string) $body['error']);
    }

    public function test_disable_returns_422_when_enabled_dependent_blocks(): void
    {
        [$registry, $tmp] = ModuleRegistryFactory::build([
            ['name' => 'mod-base', 'isCore' => false, 'defaultAvailable' => true],
            ['name' => 'mod-leaf', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-base']],
        ]);
        $this->tempDirs[] = $tmp;
        $this->rebindRegistry($registry);

        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $this->seedRow('mod-base', enabledAt: $now);
        $this->seedRow('mod-leaf', enabledAt: $now);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/tenant/modules/mod-base/state',
            $this->adminToken(),
            ['action' => 'disable'],
        );
        self::assertSame(422, $resp->status(), 'disable body: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsString('mod-leaf', (string) $body['error']);
    }

    public function test_non_tenant_admin_member_gets_403(): void
    {
        // Member role, NOT admin → controller short-circuits 403.
        $member = $this->h->seedUser('member@x.com', 'pass1234');
        // Attach with non-Admin role (defaults to Registered when none).
        $this->h->userTenants->attach($member->id(), $this->h->testTenantId, UserTenantRole::Member);
        $token = $this->h->tokenFor($member);

        $cases = [
            ['GET',  '/api/v1/backstage/tenant/modules', []],
            ['POST', '/api/v1/backstage/tenant/modules/forum/state', ['action' => 'enable']],
        ];
        foreach ($cases as [$method, $uri, $body]) {
            $resp = $this->h->authedRequest($method, $uri, $token, $body);
            self::assertSame(403, $resp->status(), "{$method} {$uri} should 403 for non-admin");
        }
    }

    public function test_admin_in_other_tenant_only_gets_403(): void
    {
        // The actor is admin in some OTHER tenant — never granted Admin in
        // the harness's test tenant. Controller's role lookup returns null
        // for the current tenant → 403.
        $otherTenantId = TenantId::generate();
        $actor = $this->h->seedUser('other-admin@x.com', 'pass1234');
        $this->h->userTenants->attach($actor->id(), $otherTenantId, UserTenantRole::Admin);
        $token = $this->h->tokenFor($actor);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/tenant/modules/forum/state',
            $token,
            ['action' => 'enable'],
        );
        self::assertSame(403, $resp->status(), 'cross-tenant body: ' . $resp->body());
    }
}
