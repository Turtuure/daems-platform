<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Governance;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingOverridesEndpointTest extends TestCase
{
    private KernelHarness $h;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        // Use the actual current date so InMemoryUserFeeOverrideRepository's
        // listForTenant(activeOnly: true) filter (which uses real now) sees
        // revoke events as already-happened rather than as future actions.
        $this->today = new \DateTimeImmutable();
        $this->h = new KernelHarness(new FrozenClock($this->today));
    }

    public function test_create_list_revoke_round_trip(): void
    {
        $admin  = $this->h->seedUser('admin@billing.test', 'pass1234', 'admin');
        $member = $this->h->seedUser('member@billing.test', 'pass1234', 'member');
        $token  = $this->h->tokenFor($admin);

        // CREATE — window straddles today so we can revoke and still test active filter
        $validFrom  = $this->today->modify('-30 days')->format('Y-m-d');
        $validUntil = $this->today->modify('+30 days')->format('Y-m-d');
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/overrides', $token, [
            'user_id'               => $member->id()->value(),
            'fee_type'              => 'BASIC',
            'override_amount_cents' => 2500,
            'valid_from'            => $validFrom,
            'valid_until'           => $validUntil,
            'reason'                => 'Opiskelija-alennus',
        ]);
        $this->assertSame(201, $resp->status(), 'create: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertNotEmpty($body['id']);
        $id = (string) $body['id'];

        // LIST
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/governance/billing/overrides', $token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['rows']);
        $this->assertSame($id, $body['rows'][0]['id']);

        // REVOKE
        $resp = $this->h->authedRequest('POST', "/api/v1/backstage/governance/billing/overrides/{$id}/revoke", $token, []);
        $this->assertSame(200, $resp->status(), 'revoke: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['revoked']);

        // LIST active_only=1 should now be empty
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/governance/billing/overrides?active_only=1', $token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertSame([], $body['rows']);
    }

    public function test_non_admin_rejected(): void
    {
        $member = $this->h->seedUser('member@billing.test', 'pass1234', 'member');
        $token  = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/overrides', $token, [
            'user_id'               => $member->id()->value(),
            'fee_type'              => 'BASIC',
            'override_amount_cents' => 2500,
            'valid_from'            => '2026-01-01',
            'reason'                => 'r',
        ]);
        $this->assertSame(403, $resp->status(), 'expected 403: ' . $resp->body());
    }
}
