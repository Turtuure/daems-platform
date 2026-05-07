<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Platform;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * GSA-only tenant_domains lifecycle endpoints.
 *
 * Uses the harness's auto-seeded `test-tenant` as the target tenant — no
 * extra create cycle needed. Every test exercises the full kernel path so
 * router + middleware + controller + use case + repository all run.
 */
final class TenantDomainsE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $gsaToken;
    private string $memberToken;
    private string $tenantId;

    protected function setUp(): void
    {
        $this->h           = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
        $gsa               = $this->h->seedPlatformAdmin('gsa-domains@x.com', 'pass1234');
        $this->gsaToken    = $this->h->tokenFor($gsa);
        $member            = $this->h->seedUser('member-domains@x.com', 'pass1234');
        $this->memberToken = $this->h->tokenFor($member);
        $this->tenantId    = $this->h->testTenantId->value();
    }

    private function addDomain(string $hostname, bool $isPrimary): void
    {
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains',
            $this->gsaToken,
            ['hostname' => $hostname, 'isPrimary' => $isPrimary],
        );
        self::assertSame(204, $resp->status(), "add {$hostname} body: " . $resp->body());
    }

    public function test_post_adds_a_domain(): void
    {
        $this->addDomain('example.test', true);

        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains',
            $this->gsaToken,
        );
        self::assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        $rows = $body['data']['domains'] ?? [];
        self::assertIsArray($rows);
        $hostnames = array_column($rows, 'hostname');
        self::assertContains('example.test', $hostnames);
    }

    public function test_get_lists_all_for_tenant(): void
    {
        $this->addDomain('one.example.test', true);
        $this->addDomain('two.example.test', false);

        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains',
            $this->gsaToken,
        );
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        $rows = $body['data']['domains'] ?? [];
        self::assertIsArray($rows);
        self::assertCount(2, $rows);
    }

    public function test_patch_promotes_primary_and_demotes_previous(): void
    {
        $this->addDomain('primary.test', true);
        $this->addDomain('secondary.test', false);

        // Promote secondary → demotes primary.
        $resp = $this->h->authedRequest(
            'PATCH',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains/secondary.test',
            $this->gsaToken,
            ['isPrimary' => true],
        );
        self::assertSame(204, $resp->status(), 'patch body: ' . $resp->body());

        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains',
            $this->gsaToken,
        );
        $rows = json_decode($listResp->body(), true)['data']['domains'] ?? [];
        self::assertIsArray($rows);
        $byHost = [];
        foreach ($rows as $r) {
            $byHost[$r['hostname']] = $r;
        }
        self::assertTrue($byHost['secondary.test']['isPrimary']);
        self::assertFalse($byHost['primary.test']['isPrimary']);
    }

    public function test_delete_removes_non_primary(): void
    {
        $this->addDomain('keepme.test', true);
        $this->addDomain('dropme.test', false);

        $resp = $this->h->authedRequest(
            'DELETE',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains/dropme.test',
            $this->gsaToken,
        );
        self::assertSame(204, $resp->status(), 'delete body: ' . $resp->body());

        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains',
            $this->gsaToken,
        );
        $rows = json_decode($listResp->body(), true)['data']['domains'] ?? [];
        self::assertIsArray($rows);
        $hostnames = array_column($rows, 'hostname');
        self::assertNotContains('dropme.test', $hostnames);
        self::assertContains('keepme.test', $hostnames);
    }

    public function test_delete_refuses_last_primary_with_422(): void
    {
        $this->addDomain('only.test', true);

        $resp = $this->h->authedRequest(
            'DELETE',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains/only.test',
            $this->gsaToken,
        );
        self::assertSame(422, $resp->status(), 'delete body: ' . $resp->body());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function test_non_gsa_gets_403(): void
    {
        $cases = [
            ['GET',    '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains', []],
            ['POST',   '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains', ['hostname' => 'fail.test', 'isPrimary' => false]],
            ['PATCH',  '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains/some.test', ['isPrimary' => true]],
            ['DELETE', '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/domains/some.test', []],
        ];
        foreach ($cases as [$method, $uri, $body]) {
            $resp = $this->h->authedRequest($method, $uri, $this->memberToken, $body);
            self::assertSame(403, $resp->status(), "{$method} {$uri} should 403 for non-GSA");
        }
    }
}
