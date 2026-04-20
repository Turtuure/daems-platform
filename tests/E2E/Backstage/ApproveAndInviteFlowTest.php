<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class ApproveAndInviteFlowTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function test_approve_member_returns_invite_url_and_member_number(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Alice Member', 'alice-e2e@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/applications/member/' . $app->id()->value() . '/decision',
            $token,
            ['decision' => 'approved', 'note' => null],
        );

        $this->assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $data = $body['data'] ?? [];
        $this->assertArrayHasKey('invite_url', $data);
        $this->assertArrayHasKey('invite_expires_at', $data);
        $this->assertArrayHasKey('activated_user_id', $data);
        $this->assertArrayHasKey('member_number', $data);

        // invite URL should start with https://test.local
        $this->assertStringStartsWith('https://test.local/', $data['invite_url']);
        $this->assertSame('00001', $data['member_number']);
    }

    public function test_second_approve_returns_422_already_decided(): void
    {
        $admin = $this->h->seedUser('admin2@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Bob Twice', 'bob-twice@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $appId = $app->id()->value();
        $url   = '/api/v1/backstage/applications/member/' . $appId . '/decision';

        // First approval succeeds
        $resp1 = $this->h->authedRequest('POST', $url, $token, ['decision' => 'approved', 'note' => null]);
        $this->assertSame(200, $resp1->status());

        // Second identical POST => 422
        $resp2 = $this->h->authedRequest('POST', $url, $token, ['decision' => 'approved', 'note' => null]);
        $this->assertSame(422, $resp2->status());

        $body = json_decode($resp2->body(), true);
        $this->assertIsArray($body);
        $fields = $body['fields'] ?? [];
        $this->assertSame('already_decided', $fields['status'] ?? null);
    }

    public function test_approve_supporter_has_no_member_number_in_response(): void
    {
        $admin = $this->h->seedUser('admin3@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        // SupporterApplication: id, tenantId, orgName, contactPerson, regNo, email, country, motivation, howHeard, status
        $app = new \Daems\Domain\Membership\SupporterApplication(
            \Daems\Domain\Membership\SupporterApplicationId::generate(), $this->h->testTenantId,
            'Acme Corp', 'Jane Doe', null, 'acme@x.com', null, 'org motivation', null, 'pending',
        );
        $this->h->supporterApps->save($app);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/applications/supporter/' . $app->id()->value() . '/decision',
            $token,
            ['decision' => 'approved', 'note' => null],
        );

        $this->assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $data = $body['data'] ?? [];
        $this->assertArrayHasKey('invite_url', $data);
        $this->assertArrayNotHasKey('member_number', $data);
    }
}
