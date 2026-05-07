<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Platform;

use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * GSA-only platform-side module availability cascade.
 *
 * Wraps a real ModuleRegistryFactory output so the dependency graph used
 * in `test_revoke_cascades_through_dependents` is deterministic — config's
 * real catalog has no chained `depends_on` today, so we have to inject a
 * synthetic A → B → C registry into the container before the kernel sees
 * the first request.
 */
final class TenantModulesE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $gsaToken;
    private string $memberToken;
    private string $tenantId;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->h           = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
        $gsa               = $this->h->seedPlatformAdmin('gsa-modules@x.com', 'pass1234');
        $this->gsaToken    = $this->h->tokenFor($gsa);
        $member            = $this->h->seedUser('member-modules@x.com', 'pass1234');
        $this->memberToken = $this->h->tokenFor($member);
        $this->tenantId    = $this->h->testTenantId->value();
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
        // Replace the registry binding before the Router singleton is built.
        // The Router is materialised on the first kernel request, so this
        // call must happen BEFORE any request fires in the test.
        $this->h->container->bind(
            ModuleRegistry::class,
            static fn (): ModuleRegistry => $registry,
        );
    }

    private function seedAvailableRow(string $slug, ?DateTimeImmutable $enabledAt = null): void
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

    public function test_grant_marks_row_available(): void
    {
        // Use the real registry — no cascade needed for this case. Pick
        // any default-available manifest (members) and revoke its
        // auto-seeded availability first to set up a clean grant.
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability',
            $this->gsaToken,
            ['action' => 'grant'],
        );
        self::assertSame(204, $resp->status(), 'grant body: ' . $resp->body());

        $row = $this->h->tenantModules->find($this->h->testTenantId, 'forum');
        self::assertNotNull($row);
        self::assertNotNull($row->availableAt());
    }

    public function test_revoke_clears_availability_and_writes_audit(): void
    {
        // Real-registry forum has no dependents → simple revoke.
        // First grant so there's an available row to revoke.
        $grant = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability',
            $this->gsaToken,
            ['action' => 'grant'],
        );
        self::assertSame(204, $grant->status());

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability',
            $this->gsaToken,
            ['action' => 'revoke', 'reason' => 'policy'],
        );
        self::assertSame(204, $resp->status(), 'revoke body: ' . $resp->body());

        $row = $this->h->tenantModules->find($this->h->testTenantId, 'forum');
        self::assertNotNull($row);
        self::assertNull($row->availableAt());
        self::assertNotNull($row->disabledAt());

        // Audit row exists with REVOKED_AVAILABILITY action. The InMemory
        // tenantModules fake captures the root revoke audit on its own
        // `$audits` property (mirroring the SQL impl's transactional bundle);
        // dependents-cascade entries go through the standalone audit repo.
        // We merge both for a single assertion.
        $allAudits = array_merge(
            $this->h->moduleAudit->entries,
            $this->h->tenantModules->audits,
        );
        $matched = array_filter(
            $allAudits,
            static fn ($e) => $e->moduleSlug() === 'forum' && $e->action() === ModuleAuditAction::REVOKED_AVAILABILITY,
        );
        self::assertCount(1, $matched);
    }

    public function test_revoke_cascades_through_dependents(): void
    {
        // Build a synthetic A → B → C dependency graph and inject before
        // any request fires.
        [$registry, $tmpDir] = ModuleRegistryFactory::build([
            ['name' => 'mod-a', 'isCore' => false, 'defaultAvailable' => true],
            ['name' => 'mod-b', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-a']],
            ['name' => 'mod-c', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-b']],
        ]);
        $this->tempDirs[] = $tmpDir;
        $this->rebindRegistry($registry);

        // Seed all three rows ENABLED for the test tenant — the cascade is
        // about disabling enabled dependents, not non-enabled ones.
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $this->seedAvailableRow('mod-a', enabledAt: $now);
        $this->seedAvailableRow('mod-b', enabledAt: $now);
        $this->seedAvailableRow('mod-c', enabledAt: $now);

        // Revoke A — expect B + C cascade-disabled, A's row revoked.
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/mod-a/availability',
            $this->gsaToken,
            ['action' => 'revoke', 'reason' => 'cascade test'],
        );
        self::assertSame(204, $resp->status(), 'revoke body: ' . $resp->body());

        // Mod A — availability cleared.
        $rowA = $this->h->tenantModules->find($this->h->testTenantId, 'mod-a');
        self::assertNotNull($rowA);
        self::assertNull($rowA->availableAt());

        // Mod B + Mod C — enabled cleared (force-disabled by cascade).
        $rowB = $this->h->tenantModules->find($this->h->testTenantId, 'mod-b');
        self::assertNotNull($rowB);
        self::assertFalse($rowB->isEnabled());
        $rowC = $this->h->tenantModules->find($this->h->testTenantId, 'mod-c');
        self::assertNotNull($rowC);
        self::assertFalse($rowC->isEnabled());

        // Audit log: 3 rows total — A REVOKED_AVAILABILITY, B + C DISABLED.
        // Root revoke goes through tenantModules.audits (transactional bundle
        // in SQL); cascade DISABLED entries go through the standalone audit
        // repo. Merge both sources for the assertion.
        $allAudits = array_merge(
            $this->h->moduleAudit->entries,
            $this->h->tenantModules->audits,
        );
        $relevant = array_values(array_filter(
            $allAudits,
            static fn ($e) => in_array($e->moduleSlug(), ['mod-a', 'mod-b', 'mod-c'], true),
        ));
        self::assertCount(3, $relevant);

        $byPair = [];
        foreach ($relevant as $e) {
            $byPair[$e->moduleSlug() . ':' . $e->action()->value] = $e;
        }
        self::assertArrayHasKey('mod-a:revoked_availability', $byPair);
        self::assertArrayHasKey('mod-b:disabled', $byPair);
        self::assertArrayHasKey('mod-c:disabled', $byPair);
    }

    public function test_cascade_skips_non_enabled_dependents(): void
    {
        [$registry, $tmpDir] = ModuleRegistryFactory::build([
            ['name' => 'mod-x', 'isCore' => false, 'defaultAvailable' => true],
            ['name' => 'mod-y', 'isCore' => false, 'defaultAvailable' => true, 'dependsOn' => ['mod-x']],
        ]);
        $this->tempDirs[] = $tmpDir;
        $this->rebindRegistry($registry);

        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        // X enabled; Y available but NOT enabled.
        $this->seedAvailableRow('mod-x', enabledAt: $now);
        $this->seedAvailableRow('mod-y', enabledAt: null);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/mod-x/availability',
            $this->gsaToken,
            ['action' => 'revoke', 'reason' => 'no-spurious-cascade'],
        );
        self::assertSame(204, $resp->status());

        // Audit: only ONE entry should exist — X's revoke. Y was not enabled
        // so the cascade leaves it alone (no audit row).
        $allAudits = array_merge(
            $this->h->moduleAudit->entries,
            $this->h->tenantModules->audits,
        );
        $audits = array_values(array_filter(
            $allAudits,
            static fn ($e) => in_array($e->moduleSlug(), ['mod-x', 'mod-y'], true),
        ));
        self::assertCount(1, $audits);
        self::assertSame('mod-x', $audits[0]->moduleSlug());
        self::assertSame(ModuleAuditAction::REVOKED_AVAILABILITY, $audits[0]->action());
    }

    public function test_revoke_requires_reason_else_422(): void
    {
        // Real-registry path again — forum module exists.
        $grant = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability',
            $this->gsaToken,
            ['action' => 'grant'],
        );
        self::assertSame(204, $grant->status());

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability',
            $this->gsaToken,
            ['action' => 'revoke'], // reason missing
        );
        self::assertSame(422, $resp->status(), 'no-reason body: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function test_non_gsa_gets_403(): void
    {
        $cases = [
            ['POST', '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability', ['action' => 'grant']],
            ['POST', '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules/forum/availability', ['action' => 'revoke', 'reason' => 'x']],
            ['GET',  '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/modules', []],
        ];
        foreach ($cases as [$method, $uri, $body]) {
            $resp = $this->h->authedRequest($method, $uri, $this->memberToken, $body);
            self::assertSame(403, $resp->status(), "{$method} {$uri} should 403 for non-GSA");
        }
    }
}
