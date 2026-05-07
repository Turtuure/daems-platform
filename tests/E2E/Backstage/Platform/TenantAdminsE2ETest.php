<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Platform;

use Daems\Domain\Tenant\UserTenantRole;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * GSA-only grant/revoke admin endpoints for tenant memberships.
 *
 * The list endpoint intentionally returns an empty array (Wave G read-side
 * follow-up — see TenantAdminsController class doc-block) and only emits
 * the count from `countAdminsForTenant`. The test pins the SHAPE of that
 * stable response so a future enrichment is a deliberate breakage.
 */
final class TenantAdminsE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $gsaToken;
    private string $memberToken;
    private string $tenantId;

    protected function setUp(): void
    {
        $this->h           = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
        $gsa               = $this->h->seedPlatformAdmin('gsa-admins@x.com', 'pass1234');
        $this->gsaToken    = $this->h->tokenFor($gsa);
        $member            = $this->h->seedUser('member-admins@x.com', 'pass1234');
        $this->memberToken = $this->h->tokenFor($member);
        $this->tenantId    = $this->h->testTenantId->value();
    }

    public function test_post_grants_admin_role_to_existing_user(): void
    {
        $target = $this->h->seedUser('target-grant@x.com', 'pass1234');

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins',
            $this->gsaToken,
            ['userId' => $target->id()->value()],
        );
        self::assertSame(204, $resp->status(), 'grant body: ' . $resp->body());

        // Verify role state directly in the InMemory repo.
        $role = $this->h->userTenants->findRole($target->id(), $this->h->testTenantId);
        self::assertSame(UserTenantRole::Admin, $role);
    }

    public function test_delete_revokes_admin_role(): void
    {
        $target = $this->h->seedUser('target-revoke@x.com', 'pass1234');
        // Pre-attach as Admin so we have something to revoke.
        $this->h->userTenants->attach($target->id(), $this->h->testTenantId, UserTenantRole::Admin);

        $resp = $this->h->authedRequest(
            'DELETE',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins/' . $target->id()->value(),
            $this->gsaToken,
        );
        self::assertSame(204, $resp->status(), 'revoke body: ' . $resp->body());

        // After revoke, the role is downgraded (or detached). Either way, the
        // user no longer has the Admin role for the tenant.
        $role = $this->h->userTenants->findRole($target->id(), $this->h->testTenantId);
        self::assertNotSame(UserTenantRole::Admin, $role);
    }

    public function test_get_returns_count_and_admins_shape(): void
    {
        // Seed two admins so the count is non-trivial.
        $a = $this->h->seedUser('admin-a@x.com', 'pass1234');
        $b = $this->h->seedUser('admin-b@x.com', 'pass1234');
        $this->h->userTenants->attach($a->id(), $this->h->testTenantId, UserTenantRole::Admin);
        $this->h->userTenants->attach($b->id(), $this->h->testTenantId, UserTenantRole::Admin);

        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins',
            $this->gsaToken,
        );
        self::assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('admins', $body['data']);
        self::assertArrayHasKey('total', $body['data']);
        self::assertSame(2, $body['data']['total']);
        // List body itself is intentionally empty until Wave G — assert that
        // contract is still in force.
        self::assertSame([], $body['data']['admins']);
    }

    public function test_non_gsa_gets_403(): void
    {
        $target = $this->h->seedUser('target-403@x.com', 'pass1234');

        $cases = [
            ['GET',    '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins', []],
            ['POST',   '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins', ['userId' => $target->id()->value()]],
            ['DELETE', '/api/v1/backstage/platform/tenants/' . $this->tenantId . '/admins/' . $target->id()->value(), []],
        ];
        foreach ($cases as [$method, $uri, $body]) {
            $resp = $this->h->authedRequest($method, $uri, $this->memberToken, $body);
            self::assertSame(403, $resp->status(), "{$method} {$uri} should 403 for non-GSA");
        }
    }
}
