<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Platform;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * GSA-only tenant lifecycle endpoints. Asserts:
 *   - POST /api/v1/backstage/platform/tenants creates a tenant + auto-seeds
 *     tenant_modules rows for every default-available, non-core module
 *     (config/modules.php currently lists 5 such manifests).
 *   - GET / GET-by-id / PATCH / suspend / reactivate happy paths.
 *   - Non-GSA caller (regular member) receives 403 on every endpoint.
 *
 * Each test seeds a fresh KernelHarness so state never leaks between cases.
 */
final class TenantsE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $gsaToken;
    private string $memberToken;

    protected function setUp(): void
    {
        $this->h           = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
        $gsa               = $this->h->seedPlatformAdmin('gsa-tenants@x.com', 'pass1234');
        $this->gsaToken    = $this->h->tokenFor($gsa);

        $member            = $this->h->seedUser('member-tenants@x.com', 'pass1234');
        $this->memberToken = $this->h->tokenFor($member);
    }

    /**
     * @return array{id: string, body: array<string, mixed>}
     */
    private function createTenant(string $slug): array
    {
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants',
            $this->gsaToken,
            [
                'slug'                   => $slug,
                'defaultLocale'          => 'en_GB',
                'memberNumberPrefix'     => '',
                'displayNamesI18n'       => ['en_GB' => 'Acme Tenant'],
                'publicDescriptionsI18n' => [],
                'supportedLocales'       => ['en_GB'],
            ],
        );

        self::assertSame(201, $resp->status(), 'create response: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);
        self::assertIsString($body['data']['id'] ?? null);

        return ['id' => (string) $body['data']['id'], 'body' => $body];
    }

    public function test_create_seeds_tenant_modules_for_default_available_manifests(): void
    {
        $created = $this->createTenant('acme');
        $tenantId = $created['id'];

        // Tenant exists in repo under the requested slug.
        $tenant = $this->h->tenants->findBySlug('acme');
        self::assertNotNull($tenant);
        self::assertSame($tenantId, $tenant->id->value());

        // Every default-available, non-core manifest got a tenant_modules row
        // with availableAt set, but enabledAt NULL (admin must flip enable).
        // The platform catalog (config/modules.php) currently has 6 such modules.
        $rows = $this->h->tenantModules->findByTenant($tenant->id);
        self::assertCount(6, $rows, 'expected 6 auto-seeded tenant_modules rows');
        foreach ($rows as $row) {
            self::assertNotNull($row->availableAt(), "{$row->moduleSlug()} should be available");
            self::assertNull($row->enabledAt(), "{$row->moduleSlug()} should not be enabled yet");
        }
    }

    public function test_list_tenants_includes_freshly_created(): void
    {
        $created = $this->createTenant('acme-list');

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/platform/tenants', $this->gsaToken);
        self::assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        $tenants = $body['data']['tenants'] ?? [];
        self::assertIsArray($tenants);

        $slugs = array_column($tenants, 'slug');
        self::assertContains('acme-list', $slugs);
        // Test tenant from harness setUp also appears.
        self::assertContains('test-tenant', $slugs);
    }

    public function test_get_tenant_returns_full_detail(): void
    {
        $created = $this->createTenant('acme-get');

        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $created['id'],
            $this->gsaToken,
        );
        self::assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        $detail = $body['data'] ?? null;
        self::assertIsArray($detail);

        self::assertSame('acme-get', $detail['slug']);
        self::assertSame('active', $detail['status']);
        self::assertNull($detail['suspendedAt']);
        // 6 default-available rows seeded → modulesAvailable=6, modulesEnabled=0.
        self::assertSame(6, $detail['modulesAvailable']);
        self::assertSame(0, $detail['modulesEnabled']);
        self::assertSame(['en_GB'], $detail['supportedLocales']);
        self::assertSame(['en_GB' => 'Acme Tenant'], $detail['displayNameI18n']);
    }

    public function test_patch_updates_basics(): void
    {
        $created = $this->createTenant('acme-patch');

        $resp = $this->h->authedRequest(
            'PATCH',
            '/api/v1/backstage/platform/tenants/' . $created['id'],
            $this->gsaToken,
            [
                // slug must round-trip unchanged (immutable)
                'slug'                   => 'acme-patch',
                'defaultLocale'          => 'fi_FI',
                'memberNumberPrefix'     => 'AC',
                'displayNamesI18n'       => ['en_GB' => 'Acme!', 'fi_FI' => 'Akme!'],
                'publicDescriptionsI18n' => ['en_GB' => 'desc'],
                'supportedLocales'       => ['fi_FI', 'en_GB'],
            ],
        );
        self::assertSame(204, $resp->status(), 'patch body: ' . $resp->body());

        // Re-fetch; expected new fields applied.
        $resp2 = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $created['id'],
            $this->gsaToken,
        );
        $detail = json_decode($resp2->body(), true)['data'] ?? null;
        self::assertIsArray($detail);
        self::assertSame('fi_FI', $detail['defaultLocale']);
        self::assertSame('AC', $detail['memberNumberPrefix']);
        self::assertSame(['fi_FI', 'en_GB'], $detail['supportedLocales']);
        self::assertSame(['en_GB' => 'Acme!', 'fi_FI' => 'Akme!'], $detail['displayNameI18n']);
    }

    public function test_suspend_then_reactivate_round_trip(): void
    {
        $created = $this->createTenant('acme-susp');

        // Suspend
        $resp1 = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $created['id'] . '/suspend',
            $this->gsaToken,
            ['reason' => 'non-payment'],
        );
        self::assertSame(204, $resp1->status(), 'suspend body: ' . $resp1->body());

        $detailResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $created['id'],
            $this->gsaToken,
        );
        $detail = json_decode($detailResp->body(), true)['data'] ?? null;
        self::assertIsArray($detail);
        self::assertSame('suspended', $detail['status']);
        self::assertIsString($detail['suspendedAt']);
        self::assertSame('non-payment', $detail['suspendedReason']);

        // Reactivate
        $resp2 = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $created['id'] . '/reactivate',
            $this->gsaToken,
        );
        self::assertSame(204, $resp2->status(), 'reactivate body: ' . $resp2->body());

        $detailResp2 = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $created['id'],
            $this->gsaToken,
        );
        $detail2 = json_decode($detailResp2->body(), true)['data'] ?? null;
        self::assertIsArray($detail2);
        self::assertSame('active', $detail2['status']);
        self::assertNull($detail2['suspendedAt']);
        self::assertNull($detail2['suspendedReason']);
    }

    public function test_non_gsa_gets_403_on_every_endpoint(): void
    {
        // First seed a tenant as GSA to have a real id for the by-id endpoints.
        $created = $this->createTenant('acme-403');
        $id = $created['id'];

        $cases = [
            ['GET',    '/api/v1/backstage/platform/tenants',                      []],
            ['GET',    '/api/v1/backstage/platform/tenants/' . $id,               []],
            ['POST',   '/api/v1/backstage/platform/tenants',                      [
                'slug'                   => 'should-fail',
                'defaultLocale'          => 'en_GB',
                'memberNumberPrefix'     => '',
                'displayNamesI18n'       => [],
                'publicDescriptionsI18n' => [],
                'supportedLocales'       => ['en_GB'],
            ]],
            ['PATCH',  '/api/v1/backstage/platform/tenants/' . $id,               [
                'slug'                   => 'acme-403',
                'defaultLocale'          => 'en_GB',
                'memberNumberPrefix'     => '',
                'displayNamesI18n'       => [],
                'publicDescriptionsI18n' => [],
                'supportedLocales'       => ['en_GB'],
            ]],
            ['POST',   '/api/v1/backstage/platform/tenants/' . $id . '/suspend',  ['reason' => 'x']],
            ['POST',   '/api/v1/backstage/platform/tenants/' . $id . '/reactivate', []],
        ];

        foreach ($cases as [$method, $uri, $body]) {
            $resp = $this->h->authedRequest($method, $uri, $this->memberToken, $body);
            self::assertSame(403, $resp->status(), "{$method} {$uri} should 403 for non-GSA");
        }
    }
}
