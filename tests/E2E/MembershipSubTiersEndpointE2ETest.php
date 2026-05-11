<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * E2E for `/api/v1/backstage/tenant-settings/membership-subtiers`.
 *
 * Pins the HTTP semantics of the read-only sub-tier (honor) catalog endpoint:
 * admin/GSA can list, regular member is 403, no auth is 401.
 * KernelHarness seeds 8 default sub-tier rows (4 slugs × {SUPPORTING, BASIC}).
 */
final class MembershipSubTiersEndpointE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-11T12:00:00Z'));
    }

    public function test_admin_can_list_sub_tiers(): void
    {
        $admin = $this->h->seedUser('admin-subtiers@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant-settings/membership-subtiers', $token);

        self::assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertCount(8, $body['data']); // 4 slugs × 2 appliesTo (SUPPORTING + BASIC)
    }

    public function test_member_cannot_list_sub_tiers(): void
    {
        $member = $this->h->seedUser('member-subtiers@x.com', 'pass1234'); // no admin role
        $token  = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant-settings/membership-subtiers', $token);

        self::assertSame(403, $resp->status());
    }

    public function test_unauthenticated_blocked(): void
    {
        $resp = $this->h->request('GET', '/api/v1/backstage/tenant-settings/membership-subtiers');
        self::assertSame(401, $resp->status());
    }
}
